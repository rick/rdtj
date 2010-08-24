#!/usr/bin/perl -w

#
#  Back-end player for rdtj (Roundeye's duct-tape jukebox)
#
#  Rick Bradley - roundeye@roundeye.net / rick@eastcore.net
#
#  please consult LICENSE file for license information
#  CHANGELOG lists history and additional contributor information
#

#
# $Header: /cvsroot/rdtj/rdtj/jukebox_player.pl,v 1.22 2001/09/28 05:56:06 roundeye Exp $
#

use strict;			        # enforce some discipline
use FindBin;			    # find this binary (standard module)
use Getopt::Long;           # command-line argument processing (standard module)
use DBI;                    # generic database connection
use DBD::mysql;             # mysql specific drivers
use lib "$FindBin::Bin";	# find local perl libs
use RDTJ::RDTJ;			    # common RDTJ routines
use RDTJ::DB;			    # RDTJ database routines
use RDTJ::Config;           # get configuration information
use RDTJ::Log;              # logging facility

# declare our variables
use vars qw(
            $dbname $dbuser $dbpass $db %db_handles %queries $DEBUG $pid
            @lines $ps %ps_tree $ps_pid $ps_ppid $filename $qid $random $songid
            $logfile $mp3_player $play_buffer $dbsleep $nosongsleep $random_wait
            $PlayerName $PlayerMessage $logfile $row $random_row $seen 
           );

# perform command-line processing (also starts logging...)
process_cmdline();

#
# set signal handlers
#

$SIG{'__DIE__'}     = \&DIE_LOG;        # "die" messages are logged
$SIG{'__WARN__'}    = \&WARN_LOG;       # "warn" messages are logged
$SIG{'HUP'}         = \&HUP;            # rotate log file on SIGHUP
$SIG{'INT'}         = \&EXIT;           # ctrl-c kills, close log gracefully
$SIG{'KILL'}        = \&EXIT;           # SIGKILL kills, close log gracefully
$SIG{'CHLD'}        = \&REAPER;         # SIGCHLD wait()s and EXIT()s

#
# define all database queries (and associate with "handle" names)
#
%queries = (
            'register'      => 'replace into player set name=?, message=?',
            'next song'     => 'select action, filename, qid from songs, queue'
                               .' where player=? and action in (0,1) and status = 0'
                               .' and if(action=0, songid=song, songid=1)'
                               .' order by action desc,qid asc limit 1;',
            'mark playing'  => 'update queue set status=1 where qid=? and player=?',
            'done playing'  => 'update queue set status=2 where action = 0 and status = 1 and player=?',
            'skip playing'  => 'update queue set status=2 where qid=? and player=?',
            'random mode'   => 'select defaultaction from player where name=?',
            'random song'   => 'select songid from songs order by RAND() limit 1',
            'insert song'   => 'insert into queue (action, status, song, origin, player)'
                               ." values (0,0,?,'[random]', ?)",
            'find 86'       => 'select qid from queue where player=? and action=86 limit 1',
            'clear 86'      => 'delete from queue where action=86 and player=?',
#TODO: have this called prior to current fatal signal handlers...
            'clean up'      => 'delete from player where name=?',
           );

if ($pid = fork())
{
    # parent --> "vigilante" (kills player instances when instructed)

    LOG("Vigilante process pid = $$, player process pid = $pid");
 
    VIGDB:
    while (1)
    {
        # connect to database
        $db = db_connect($dbname, $dbuser, $dbpass) or do { sleep $dbsleep; next VIGDB; };
        LOG("vigilante: connected to database $dbname as user $dbuser");

        # register queries with database module
        db_register(\%queries, \%db_handles) or die "Cannot register database queries!\n";

        # clean up any old '86's still in the database
        db_execute('clear 86', $PlayerName) or do { sleep $dbsleep; next VIGDB; };

        VIGSONG:
        while (1)
        {
            # look for an '86' (kill player) command
            db_execute('find 86', $PlayerName) or do { sleep $dbsleep; next VIGDB; };

            $seen = 0;
            while ($row = $db_handles{'find 86'}->fetchrow_hashref)
            {
                $seen = 1;                          # we've seen an action

                # hunt down the current player
                { 
                    # disable SIGCHILD for this command...
                    local $SIG{'CHLD'} = 'IGNORE';
                    @lines = `$ps -falxwww`;
                }
                %ps_tree = ();                       # clear process tree hash
                foreach (@lines)
                {   # find the player's pid
                    next unless /$mp3_player/o;     # must be a player
                    # mpg123 runs a process (P) which spawns a child (C)
                    # to kill it immediately and cleanly you must 
                    # kill -INT C then kill -INT P

                    # get process information
                    (undef, undef, $ps_pid, $ps_ppid, undef) = split();
                    # store parent/child pid info 
                    $ps_tree{$ps_ppid} = $ps_pid;
                }

                # kill the player
                if (defined $ps_tree{$pid} and defined $ps_tree{$ps_tree{$pid}})
                {
                    LOG("killing $ps_tree{$ps_tree{$pid}}");
                    kill(9,$ps_tree{$ps_tree{$pid}}); 
                    LOG("killing $ps_tree{$pid}");
                    kill(9,$ps_tree{$pid});
                }
                else
                {
                    LOG("couldn't find process to kill (player = [$mp3_player])");
                }

                # clean up '86' entry
                db_execute('clear 86', $PlayerName) or do { sleep $dbsleep; next VIGDB; };
            }

            # don't busy-wait if there is no action
            sleep $nosongsleep unless $seen;
        }
    }
}
else
{
    # child --> player (plays songs)

    $SIG{'CHLD'} = 'IGNORE';        # verily you may kill mine children

    # query database for mp3's to play, play 'em, update queue
    DB:
    while (1)
    {   
        # connect to database
        $db = db_connect($dbname, $dbuser, $dbpass) or do { sleep $dbsleep; next DB; };
        LOG("connected to database $dbname as user $dbuser");

        # register queries with database module
        db_register(\%queries, \%db_handles) or die "Cannot register database queries!\n";

        # register this player
        # NOTE: contention checking is tough so we don't do any ;-P
        LOG("Registering player $PlayerName with message $PlayerMessage");
        db_execute('register', $PlayerName, $PlayerMessage) or do { sleep $dbsleep; next DB; };
        LOG("player $PlayerName successfully registered");

        # get rid of any orphaned "playing" tracks in queue
        db_execute('done playing', $PlayerName) or do { sleep $dbsleep; next DB; };
        LOG("Cleaned any old 'playing' tracks from queue for player $PlayerName");

        # repeatedly get and play a song
        SONG:
        while (1)
        {
            $seen = 0;

            # find the next song or action in our queue
            db_execute('next song', $PlayerName) or do { sleep $dbsleep; next DB; };

            if ($row = $db_handles{'next song'}->fetchrow_hashref)
            {   # song or action found
                $random_wait = 0;       # clear "waiting for random song" flag
                if (!$row->{'action'})
                {   # action == "play"
					$seen = 1;              			# we've seen a playable song
                    $qid = $row->{'qid'};               # get the queue id
                    $filename = $row->{'filename'};     # get the file name
                    if (-f $filename)
                    {	# file is there so try to play the song
                        LOG("Playing $filename");

                        # update "current track" status in queue
                        db_execute('mark playing', $qid, $PlayerName) or do { sleep $dbsleep; next DB; };

                        # actually play the mp3 song
                        # system($mp3_player, '-v', "-b $play_buffer", $filename); # verbose, but hell on logfiles
                        system($mp3_player, "-b $play_buffer", $filename);

                        # move song to "played" status in queue
                        db_execute('done playing', $PlayerName) or do { sleep $dbsleep; next DB; };
                    }
                    else
                    {   # skip this unplayable track
                        LOG('error', "Cannot play song [$filename]...  Skipping.");
                        db_execute('skip playing', $qid, $PlayerName) or do { sleep $dbsleep; next DB; };
                    }
                }
                else
                {   # non-"play" action
                }
            }
            else
            {   # no songs or actions
                # query the setting for random play mode
                db_execute('random mode', $PlayerName) or do { sleep $dbsleep; next DB; };
                $random = 0;
                while ($row = $db_handles{'random mode'}->fetchrow_hashref)
                {
                    $random = ($row->{'defaultaction'} == 1 ? 1 : 0);
                }

                if ($random)
                {   # random song mode is active
                    # check flag -- are we waiting for a random song to show up?
                    if ($random_wait and $random_wait < $RDTJ::Config::random_limit)
                    {   # waiting, but haven't waited long enough to give up
                        $random_wait++;     # track how long we wait
                        sleep 1;            # just wait a little...
                        next SONG;          # and check song list again
                    }

                    $random_wait = 0;

                    # pick a random song
                    db_execute('random song') or do { sleep $dbsleep; next DB; };

                    if(($random_row = $db_handles{'random song'}->fetchrow_hashref) and 
                       ($songid = $random_row->{'songid'}))
                    {
                        #  update queue with random song
                        db_execute('insert song', $songid, $PlayerName) or do { sleep $dbsleep; next DB; };

                        # initialize "waiting for random song" flag -- this
                        # addresses some annoying MySQL timing issues
                        $random_wait = 1;

                        # go back and check song list
                        next SONG;
                    }
                }
            }
            # don't busy-wait if there are no songs
            sleep $nosongsleep unless $seen;
        }
    }
}


# command line processing
sub process_cmdline
{
    my $prog = $0;                  # what's my name baby?
    $prog =~ s:.*?([^/]+)$:$1:;     # just a little trim job

    # allow CVS to keep track of versions and last updates :-)
    my $version = '(CVS revision #) $Revision: 1.22 $';     $version    =~ s/\$//g;
    my $lastupdate = '$Date: 2001/09/28 05:56:06 $';      $lastupdate =~ s/\$//g;

    my $usage = <<EOU;
Usage:  $0 -n playername [options]

Version $version last updated $lastupdate.

RDTJ - Roundeye's duct-tape jukebox.  This is the back-end player
script.  Install on a machine with a sound card (that hopefully has
access to your directories of mp3's, *and* has access to your database).
This program will connect to the database, and repeatedly pick the next
song from the queue, play it, and update the queue.

    General options


    -n, --name			            Name of this player (queue name on front-end)
    -m, --message		            Player description message
    -h, -?, --help                  Display this message
    -v, --version                   Output version info and exit
    -d, --debug                     Turn on debugging (default = off)
    -l file, --logfile file         Set log file to 'file'
                                    (use '-' for stdout)
    --nolog                         Disable logging

    --dbsleep seconds               # seconds to sleep if database down
    --qsleep  seconds               # seconds to sleep on empty queue
    --ps                            location of 'ps' binary

    Database options

    --db     name                   Name of database to connect to
    --dbhost hostname               Hostname to use for database connection
    --dbport port                   Port number to use for database connection
    --dbuser username               Username to use for database connection
    --dbpass password               Password to use for database connection
                                     (warning:  this is visible to 'ps'!)
    Player options
    
    -p path, --player path          Path to command-line mp3 player
                                    (default = /usr/bin/mpg123)
    -b size, --buffer size          Player buffer size in kilobytes
                                    (default = 1024)
                                    
Homepage: http://rdtj.sourceforge.net
Author:   Rick Bradley (roundeye\@roundeye.net / rick\@eastcore.net)

EOU

    # variables to store commandline args
    my ($help, $ver, $debug, $log_file, $nolog, $db_sleep, $q_sleep,
        $db_name, $db_host, $db_port, $db_user, $db_pass, $player, 
        $buffer, $name, $message, $local_ps);

    # don't ignore case -- in case we want to differentiate between '-x'/'-X'
    Getopt::Long::Configure('no_ignore_case');

    # retrieve the command line options
    GetOptions(
	       'name|n=s'	    => \$name,
	       'message|m=s'	=> \$message,
           'help|h|?'       => \$help,
           'version|v'      => \$ver,
           'debug|d'        => \$debug,
           'logfile|l=s'    => \$log_file,
           'nolog'          => \$nolog,
           'dbsleep=i'      => \$db_sleep,
           'qsleep=i'       => \$q_sleep,
           'db=s'           => \$db_name,
           'dbhost=s'       => \$db_host,
           'dbport=s'       => \$db_port,
           'dbuser=s'       => \$db_user,
           'dbpass=s'       => \$db_pass,
           'player|p=s'     => \$player,
           'buffer|b=s'     => \$buffer,
           'ps=s'           => \$local_ps,
    ) &&!$help &&!$ver or die $usage;

    die $usage if $ver;
    die $usage unless $name;			    # player name is mandatory

    # set defaults from RDTJ::Config
    $dbname 		= $RDTJ::Config::dbname;
    $dbuser 		= $RDTJ::Config::dbuser;
    $dbpass 		= $RDTJ::Config::dbpass;
    $dbsleep 		= $RDTJ::Config::dbsleep;
    $nosongsleep 	= $RDTJ::Config::nosongsleep;
    $mp3_player		= $RDTJ::Config::mp3_player;
    $play_buffer	= $RDTJ::Config::play_buffer;
    $DEBUG		    = $RDTJ::Config::DEBUG;
    $logfile		= $RDTJ::Config::logfile;
    $ps             = $RDTJ::Config::ps;
    
    # set local defaults
    $PlayerName = $name;			    # set global player name
    $PlayerMessage = $message || '';		    # set global player message

    # prepare for the inevitable
    my $dieflag = 0;
    my $dieerror = '';

    # process command-line arguments

    $DEBUG = $debug      if (defined $debug);       # set global DEBUG flag
    $logfile = $log_file if (defined $log_file);    # set global logfile

    if (defined $db_sleep)
    {   # validate positive integer
        if ($db_sleep <= 0)
        {
            $dieflag = 1;
            $dieerror .= "--dbsleep must specify a number > 0\n";
        }
        else
        {
            $dbsleep = $db_sleep;                   # set global $dbsleep
        }
    }

    if (defined $q_sleep)
    {   # validate positive integer
        if ($q_sleep < 0)
        {
            $dieflag = 1;
            $dieerror .= "--qsleep must specify a number >= 0\n";
        }
        else
        {
            $nosongsleep = $q_sleep;                # set global $nosongsleep
        }
    }

    if (defined $db_port and ($db_port < 0 or $db_port > 65535))
    {   # port number is out of range
        $dieflag = 1;
        $dieerror .= "--dbport must be between 0 and 65535\n";
    }

    if (defined $db_name or defined $db_host) 
    {   # must update the $dbname spec.  
        if (!defined $db_host) 
        {   # just the database name changed
            $dbname = "DBI:mysql:$db_name";              # set global $dbname 
        }
        else 
        {   # hostname changed... more tricky 
            $db_name ||= "mp3";  # revert to default
            # set global $dbname
            $dbname = "DBI:mysql:database=$db_name:host=$db_host" .  
                (defined $db_port ? ":port=$db_port" : ''); 
        } 
    } 
    else 
    {   # no changes in host or db name, but wiseguys may set port 
        if (defined $db_port) 
        {   # port on what?... (I'm probably making an error here...)
            $dieflag = 1; 
            $dieerror .= "--dbport requires --dbhost to be set\n"; 
        } 
    }

    $dbuser = $db_user if (defined $db_user);       # set global $dbuser
    $dbpass = $db_pass if (defined $db_pass);       # set global $dbpass
    $mp3_player = $player if (defined $player);     # set global $mp3_player

    if (!-x $mp3_player)
    {   # we can't run the mp3 player!  Lot of good that'll do :-)
        $dieflag = 1;
        $dieerror .= "Can't run the mp3 player:  $mp3_player\n";
    }

    if (defined $buffer)
    {   # validate positive integer
        if ($buffer < 0)
        {
            $dieflag = 1;
            $dieerror .= "--buffer must specify a number >= 0\n";
        }
        else
        {
            $play_buffer = $buffer if (defined $buffer);    # set global $play_buffer
        }
    }

    $ps = $local_ps if (defined $local_ps);

    if (! -x $ps)
    {   # can't run this 'ps', so what's the point?
        $dieflag = 1;
        $dieerror .= "cannot run ps program [$ps]\n";
    }

    # seasons don't fear the reaper
    die "\n$dieerror\n$usage" if $dieflag;   

    # start logging unless disabled
    open_LOG($logfile) unless $nolog;
}

# when parent (vigilante) receives a SIGCHLD then the child (player) has exited
# 
# parent waits to avoid zombies then calls EXIT itself, which should shut down
# cleanly.
sub REAPER
{
    LOG("Saw a SIGCHLD.");
    wait;
    LOG("Player exited.  Shutting down.");
    EXIT();
}
