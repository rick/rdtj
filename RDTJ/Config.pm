package RDTJ::Config;

# machinations to allow us to include this file from both Perl[5] and PHP[4]
# flames to roundeye@roundeye.net / rick@eastcore.net
my $junk = <<PHP;
<?php /*
PHP
$junk .= qq;*/; ;

#
#  Perl configuration info for rdtj (Roundeye's duct-tape jukebox)
#
#  Rick Bradley - roundeye@roundeye.net / rick@eastcore.net
#
#  please consult LICENSE file for license information
#  CHANGELOG lists history and additional contributor information
#
#
# $Header: /cvsroot/rdtj/rdtj/RDTJ/Config.pm,v 1.4 2001/09/27 01:58:06 roundeye Exp $
#

#------------------------------------------------------------
#
# DEFAULT CONFIGURATION SETTINGS -- please check!
#
#  (note: these can often be overriden on the command line)
#
#------------------------------------------------------------

# database settings
$dbname		= 'DBI:mysql:database=mp3;host=host';	# name of database to connect to
$dbuser		= "user";				# database user name to use when connecting
$dbpass		= "password";				# password for database user

# player sleep times
$dbsleep	= 20;					# no. seconds to sleep before retrying a database connection
$nosongsleep	= 5;					# no. seconds to sleep if there are no songs to play

# player info
$mp3_player	= '/usr/bin/mpg123';			# where is the command-line mp3 player?
$play_buffer	= 1024;					# player buffer in kilobytes

# misc.
$DEBUG		= 0;					# do debug-ish logging
$logfile	= '-';					# where does the logging go?
$ps		= '/bin/ps';				# where is the local version of "ps"?
$random_limit	= 5;					# seconds (+1) to wait for random song to appear in database

#------------------------------------------------------------
#
#
#  End configuration... move along.  move along.
#
#
#------------------------------------------------------------

# modules must return true
1;

