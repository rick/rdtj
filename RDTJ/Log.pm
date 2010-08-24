#!/usr/bin/perl -w

#
#  RDTJ::Log  - logging routines for the rdtj (Roundeye's Duct-Tape Jukebox)
#
#  Rick Bradley - roundeye@roundeye.net / rick@eastcore.net
#
#  please consult LICENSE file for license information
#  CHANGELOG lists history and additional contributor information
#

#
# $Header: /cvsroot/rdtj/rdtj/RDTJ/Log.pm,v 1.1 2001/09/23 19:54:41 roundeye Exp $
#

package RDTJ::Log;

require Exporter;
@ISA	= qw(Exporter);
@EXPORT = qw(LOG open_LOG close_LOG enable_LOG disable_LOG);

#
#  Logging routines
#

# write a message to the log facility
sub LOG
{
	return unless $LOGenabled;
	my $status = shift;
	my $message = shift;
	my $timenow = localtime();

	# the first argument is optional and defaults to 'info'
	if (not defined $message)
	{
		$message = $status;
		$status = 'info';
	}

	$status = uc($status);
	
	chomp($message);
	print LOGFILE "$0: [$status] ($timenow) $message\n";

	print "$0: [$status] ($timenow) $message\n" if $DEBUG;
}

# initialize the message log
sub open_LOG
{
	my $logfile = shift;
	my $timenow = localtime();

    if ($logfile eq '-')
    {
        *LOGFILE = *STDOUT;     # just use STDOUT instead of a file
    }
    else
    {
        open (LOGFILE, ">> $logfile") or die "$0: cannot open logfile $logfile: $!\n";
    }
	enable_LOG();

	LOG('startup', "Logging facility initialized at $timenow.");
}

sub close_LOG
{
	return unless $LOGenabled;
	LOG("logger shutting down");
	close(LOGFILE);
	disable_LOG();
}

# enable creation of the log
sub enable_LOG
{
	$LOGenabled = 1;
}

# disable creation of the log
sub disable_LOG
{
	$LOGenabled = 0;
}


# modules must return true
1;

