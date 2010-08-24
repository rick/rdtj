#!/usr/bin/perl -w

#
#  Common perl database routines for rdtj (Roundeye's duct-tape jukebox)
#
#  Rick Bradley - roundeye@roundeye.net / rick@eastcore.net
#
#  please consult LICENSE file for license information
#  CHANGELOG lists history and additional contributor information
#

#
# $Header: /cvsroot/rdtj/rdtj/RDTJ/DB.pm,v 1.2 2001/09/27 01:52:09 roundeye Exp $
#

package RDTJ::DB;

require Exporter;
@ISA	= qw(Exporter);
@EXPORT	= qw(db_connect db_register db_prepare db_execute);

use strict;					            # enforce some discipline
use RDTJ::Log;
use RDTJ::Config;

use vars qw($db $queries $handles);

#
# Subroutines
#

# establish a database connection
sub db_connect
{
    my ($dbname, $dbuser, $dbpass) = @_;
    $db = DBI->connect ($dbname, $dbuser, $dbpass);
    LOG('error', "Could not connect to database $dbname as user $dbuser!") unless $db;
    $db;
}

# receive queries and database handles from caller, prepare all queries
# PREREQ:  must have already successfully called db_connect
sub db_register
{
    my ($my_queries, $my_handles) = @_;

    return 0 unless (defined $my_queries and defined $my_handles);

    # save handles and queries in this module
    $queries = $my_queries;
    $handles = $my_handles;

    my ($key, $handle);
    foreach $key (keys %{$my_queries})
    {
	LOG("query:: $key -> [".$my_queries->{$key}.']') if $RDTJ::Config::DEBUG;
	$handle = db_prepare($my_queries->{$key});
	if (!$handle)
	{
	    LOG("Couldn't prepare query [$key => ".$my_queries->{$key}.']');
	    return 0;
	}
	$handles->{$key} = $handle;
    }
    1;
}

# prepare a query string on the database connection $db
sub db_prepare
{
    my ($query) = shift;

    # basic sanity checking
    if (not defined $db)
    {
        LOG('error', "Cannot prepare query [$query] -- no database connection.");
        return 0;
    }

    # prepare the query
    my $handle = $db->prepare($query) or 
    do
    {
        LOG('error', "Could not prepare SQL query [$query] (".$db->errstr.") -- disconnecting.");
        $db->disconnect();
        return 0;
    };

    # and return the query handle
    $handle;
}

# execute a database query specified by handle (name)
sub db_execute
{
    my ($query, @args) = @_;

    # basic sanity checking
    if (!(defined $db and defined $queries and
          defined $handles->{$query} and defined $queries->{$query}))
    {
        LOG('error', "Cannot execute query by name [$query]!");
        return 0;
    }

    my $handle = $handles->{$query};

    # execute the query
    # LOG("Executing query [$query => ".$queries->{$query}."]".(scalar(@args) ? ' with args ('.join(',', @args).')' : ''));
    (scalar(@args) ? $handle->execute(@args) : $handle->execute()) or
    do
    {
        LOG('error', "Could not execute query [".$queries->{$query}."] (".$handle->errstr.") -- disconnecting...");
        $db->disconnect();
        return 0;
    };
    1;
}


# modules must return true
1;

