#!/usr/bin/perl -w

#
#  Common perl routines for rdtj (Roundeye's duct-tape jukebox)
#
#  Rick Bradley - roundeye@roundeye.net / rick@eastcore.net
#
#  please consult LICENSE file for license information
#  CHANGELOG lists history and additional contributor information
#

#
# $Header: /cvsroot/rdtj/rdtj/RDTJ/RDTJ.pm,v 1.2 2001/09/26 05:47:47 roundeye Exp $
#

package RDTJ::RDTJ;

require Exporter;
@ISA = qw(Exporter);
@EXPORT = qw(DIE_LOG WARN_LOG HUP EXIT);

use strict;					            # enforce some discipline
use RDTJ::Log;

#
# signal handler subroutines
#

sub DIE_LOG
{
    my ($i, $frame);

	LOG('error', "DIE-ing with message [$_[0]]");
	LOG('error', 'stack trace:');
	for ($i=0;;$i++)
	{
	    $frame = join(':', caller($i));
	    last unless $frame;
	    LOG('error', $frame);
	}
	exit(1);
}

sub WARN_LOG
{
	LOG('warning', "internal warning message [$_[0]]");
}

sub HUP
{	# reset logging, rotate log file out of the way (timestamp it)
    my $date;
	close_LOG();

    if ($::logfile ne '-')
    {   # rotate logfile (don't bother with stdout :-)
        $date = `/bin/date +_%Y%m%d%H%M%S`;     # generate a sortable timestamp
        chomp($date);
        system('/bin/mv', $::logfile, $::logfile.".".$date);    # rotate
    }

	open_LOG($::logfile);       # and get back to business
	$SIG{'HUP'} = \&HUP         # reinstall signal handler
}

sub EXIT
{   # clean up before dying
    close_LOG();
    exit(0);
}

sub REAPER
{
    wait;   # avoid the zombie
    die "Shutting down.";
}

#
# end of module
#

# modules must return true
1;

