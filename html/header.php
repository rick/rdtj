<?php

    #
    #  Header information for PHP interface to RDTJ (Roundeye's Duct-Tape Jukebox)
    #
    #  please consult LICENSE file for license information
    #  CHANGELOG lists history and additional contributor information
    #  

    #
    #  $Header: /cvsroot/rdtj/rdtj/html/header.php,v 1.14 2001/09/27 01:52:09 roundeye Exp $
    #

    # default player:

    $default_player = 'jukebox';

    #
    # look-and-feel configuration
    #

    # ADMIN OPTIONS
    $admin_pass		= "pass";	# password for administrative functions
					# (should be md5+database eventually)
    $admin_allow_song	= 1;		# allow admin to modify song details?
    $admin_allow_album  = 1;		# allow admin to modify album details?
    $admin_allow_artist = 1;		# allow admin to modify artist details?

    # DATABASE OPTIONS

    $dbhost = "host";			# which host is the database server?
    $dbuser = "user";			# which user should connect to the db?
    $dbpass = "password";		# what is the db user's password?
    $dbname = "mp3";			# what is the name of the database?

    # COLORS
    $color_page		= "#f2f2d0";	# background color for page
    $color_request	= "#f9fce8";	# background color for requests area
    $color_status	= "#71b8f2";	# background color for current song area
    $color_controls	= "#a2d2f2";	# background color for player controls
    $color_hits		= "#bffce6";	# background color for hits area
    $color_songs	= "#a1f2f4";	# background color for collapsed song list
    $color_text		= "#000000";	# default text color
    $color_error_page	= "#f4f44b";	# background color for error sections
    $color_error_msg	= "#f21515";	# background color for error messages

    # STATUS OPTIONS
    $status_title	= "Jukebox Status"; # headline to show above status area
    $status_album	= 1;		# display album for current song?
    $status_length	= 1;		# show song length for current song?
    $status_show_IP	= 1;		# show IP for current song?
    $status_play_line	= "Now playing:";    # "now playing" message text

    # PLAYER CONTROL OPTIONS
    $controls_on	= 1;		# enable player controls?
    $controls_show_IP	= 1;		# show IP in player controls?
    $controls_show_time = 1;		# show elapsed time in player controls?

    # REQUEST OPTIONS
    $request_title	= "Requests";	# headline to show above expanded requests
    $request_album	= 1;		# display albums in request list?
    $request_length	= 1;		# show track length in request list?
    $request_show_IP	= 1;		# show IP's in request list?
    $request_wait_time	= 1;		# show "requested xxx ago" info for requests?
    $request_kill	= 1;		# display "kill" links in requests?
    $request_close	= 1;		# "close" link on the expanded requests area?
    $mass_murder	= 1;		# display mass-delete widgets in requests?

    # HITS OPTIONS
    $hits_title		= "Greatest Hits";  # headline to show above expanded hits
    $hits_default	= 10;		# default number of hits to show initially
    $hits_all		= 1000000;	# number of hits to request for "all hits"
    $hits_close		= 1;		# "close" link on the expanded hits area?

    # SONG LIST OPTIONS
    $songs_title	= "";		# headline to show above song list

    # MISC. OPTIONS
    $refresh_on		= 1;		# should the page refresh?
    $refresh_time	= 150;		# how many seconds before a refresh?
    $refresh_time_small = 30;		# refresh time when song list is hidden


    # function to return a human-readable time from a number of seconds
    function readable_time ($time='')
    {
	if (!$time) { return('0:00'); }  # no time left for you

	# break down into common units
	$days  = floor($time/86400);
	$hours = floor(($time % 86400) / 3600);
	$min   = floor(($time % 3600) / 60);
	$sec   = $time % 60;

	# generate a minutes and seconds string (e.g., "4:32");
	$minsec = sprintf("%d:%02d", $min, $sec);

	$hstr = ($hours == 1 ? 'hour' : 'hours');
	$dstr = ($days  == 1 ? 'day'  : 'days');

	# return the appropriate readable string
	if ($days)  { return ("$days $dstr $hours $hstr $minsec"); }
	if ($hours) { return ("$hours $hstr $minsec"); }
	return ($minsec); 
    }

    # output a "player dead" page
    function player_dead()
    {
	global $color_error_page, $color_status;
	# be sure to close opened nesting from caller
	?>
	<br>
	</center>
	</td></tr></table>
	  <table width="99%" border="0" cellpadding="0" cellspacing="0" bgcolor="<?php print $color_error_page ?>">
	    <tr>
	      <td>
		<blockquote>
		<br>
		<h1>System Down</h1>
		<p align="left">
		    The backend player system appears to be down.  This could
		    be due to a misconfiguration (have you set the correct
		    host, username, and password for your database
		    connection?).  Perhaps the database server is not running?
		</p>
		<br>
		</blockquote>
	      </td>
	    </tr>
	  </table>
	<?php
	include "./footer.php";
    }

    # output a "no players" page
    function no_players()
    {
	global $color_error_page, $color_status;
	# be sure to close opened nesting from caller
	?>
	<br>
	</center>
	</td></tr></table>
	  <table width="99%" border="0" cellpadding="0" cellspacing="0" bgcolor="<?php print $color_error_page ?>">
	    <tr>
	      <td>
		<blockquote>
		<br>
		<h1>No Players!</h1>
		<p align="left">
		    No back-end player systems are registered.  Perhaps this
		    is a new installation and the maintainer hasn't started
		    a player yet.  Perhaps something wacky is happening.
		    Regardless, nobody's jammin' out.
		</p>
		<br>
		</blockquote>
	      </td>
	    </tr>
	  </table>
	<?php
	include "./footer.php";
    }

    # try to make addslashes code work consistently
    $slashes = (get_magic_quotes_gpc() ? 0 : 1);

    # get the client's IP for whatever reason...
    $IP = getenv('REMOTE_ADDR');
    # attempt to resolve the hostname
    $IP = @gethostbyaddr($IP);
    if ($slashes)
    {
	$IP = addslashes($IP);
    }

    # output page header
?>
<html>
  <head>
    <title>Duct-tape Jukebox!</title>
	<meta voo="doo">
<?php
    if ($refresh_on and !($admin_artist or $admin_album or $admin_songs))
    {	# do automatic page refreshes
	# but please lord not when someone's doing admin
	$refresh = ($songs_closed ? $refresh_time_small : $refresh_time);
	if (isset($list))
	{
	?>
	<META HTTP-EQUIV="Refresh" CONTENT="<?php print $refresh ?>;URL=index.php?list=1&player=<?php print $player ?>&songs_closed=<?php print $songs_closed ?>">
	<?php
	}
	else
	{
	?>
	<META HTTP-EQUIV="Refresh" CONTENT="<?php print $refresh ?>;URL=index.php?player=<?php print $player ?>&songs_closed=<?php print $songs_closed ?>">
	<?php
	}
    }
?>
  </head>
  <body bgcolor="<?php print $color_page ?>" text="<?php print $color_text ?>">
  <!-- spacer --><table bgcolor="#000000" width="99%" border="0" cellspacing="0" cellpadding="0"><tr><td><img height="5" src="1x1.gif"></td></tr></table>
