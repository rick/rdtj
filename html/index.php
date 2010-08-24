<?php
#
# Web interface for rdtj (Roundeye's duct-tape jukebox)
#
# Rick Bradley - roundeye@roundeye.net / rick@eastcore.net
#
# please consult LICENSE file for license details
# CHANGELOG lists history and additional contributor information
# 

#
# $Header: /cvsroot/rdtj/rdtj/html/index.php,v 1.21 2001/09/27 01:52:09 roundeye Exp $
#

    # include HTML header and configuration options
    include "./header.php";

    # these sections can fail and broadcast big nasty error messages... prepare
    # for graceful capture:
?><table width="99%" border="0" cellpadding="0" cellspacing="0" bgcolor="<?php print $color_error_msg ?>"><tr><td><!-- spacer --><table bgcolor="#000000" width="100%" border="0" cellspacing="0" cellpadding="0"><tr><td><img height="1" src="1x1.gif"></td></tr></table><center><?php
    # connect to the database
    $dbi = mysql_connect($dbhost, $dbuser, $dbpass);
    if (!$dbi)
    {   # connection to database server failed
	return player_dead();
    }

    # connect to database
    $result = mysql_select_db($dbname, $dbi);
    if (!$result)
    {   # connection to database failed
	return player_dead();
    }

    #
    #	GET PLAYER NAMES AND DESCRIPTIONS
    #

    if ($player and $slashes)
	$player = addslashes($player);

    $players = array();
    $player_desc = array();
    $res = mysql_query('select name,message,defaultaction from player order by name,message', $dbi);
    if ($res and mysql_num_rows($res))
    {
	$player_count = 0;
	while (list($name, $message, $defaultaction) = mysql_fetch_row($res))
	{
	    $players[$count] = $name;
	    $player_desc[$name] = $message;
	    $player_action[$name] = $defaultaction;
	    $count++;
	}
    }

    if (!$count)
    {	# no players... sorry, bub.
	return no_players();
    }
?></center></td></tr></table><?php

    # no player specified?  default player then first player in list
    if (!$player) $player = ($default_player ? $default_player : $players[0]);
    $safeplayer = addslashes($player);

    #
    #  DETERMINE PLAYER STATUS
    #

    # let's go ahead now and get the status of the player
    $res = mysql_query('select action, (UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(entered)), '.
		       "origin from queue where player='$safeplayer' and action != 0 order by qid limit 1", $dbi);
    if ($res and mysql_num_rows($res) and 
	list($action, $player_status_when, $player_status_IP) = mysql_fetch_row($res))
    {
	switch($action)
	{
	    case 1:
		$player_status = 'paused';
	    break;
	    
	    case 86:
		$player_status = 'updating';
	    break;

	    default:
		$player_status = 'unknown';
	    break;
	}
    }
    else
    {
	$player_status = 'playing';
    }
    mysql_free_result($res);	# be nice to the poor little db

    #
    #  command processing
    #

    # set default action command
    if (isset ($defaction))
    {
    	$player_action[$player] = $defaction;
	mysql_query("update player set defaultaction=$defaction where name='$safeplayer'", $dbi);
    }

    # "move songs to" operation
    if ($mfrom and $mto)
    {
	if ($slashes)
	{
	    $mfrom = addslashes($mfrom);
	    $mto = addslashes($mto);
	}
	mysql_query("update queue set player='$mto' where player='$mfrom' and action=0 and status=0", $dbi);
    }

    if (isset($submit))
    {	# new song or album submitted
	if (preg_match('/([0-9]+,)+[0-9]+/', $submit))
	{   # looks like an album submission to me :-)
	    $list = explode (',', $submit);
	}
	else
	{
	    if (!preg_match('/[^0-9]/', $submit))
	    {	# make sure a single request is valid
		$list = array();
		$list[0] = $submit;
	    }
	}
	if (is_array($list) and count($list))
	{   # queue up requests if they made sense
	    while (list($k, $v) = each ($list))
	    {
		$res = mysql_query("insert into queue (action, status, song, origin,". 
		    "player) values (0, 0, $v, '$IP', '$safeplayer')", $dbi);
		if (!mysql_affected_rows())
		    // mysql_affected_rows appears to flake out with $res as an arg
		{
		    print "<b><font color=\"red\">Warning -- could not submit request!</font></b><br>\n";
		}
	    }
	}
    }

    if (isset($kill))
    {	# delete a request from the queue
	$kill_list = '';
	if (is_array($kill))
	{   # a list of requests to kill
	    while (list($k, $v) = each ($kill))
	    {	# build a list suitable for the delete query
		if (!preg_match('/[^0-9]/', $k))
		{   # make sure we have valid numbers
		    $kill_list .= ($kill_list ? ',':'').$k;
		}
	    }
	}
	else
	{
	    if (!preg_match('/[^0-9]/', $kill))
	    {
		$kill_list = $kill;
	    }
	}

	if ($kill_list)
	{   # only do delete if there were valid numbers 
	    $res = mysql_query("delete from queue where qid in ($kill_list)", $dbi);
	    if (!mysql_affected_rows())
		// mysql_affected_rows appears to flake out with $res as an arg
	    {
		print "<b><font color=\"red\">Warning -- could not delete request(s) $kill_list!</font></b><br>\n";
	    }
	}
    }

    #
    #  process admin commands
    #

    # clear buffer for admin errors
    $admin_error = '';

    if ($admin_artist_submit and $admin_allow_artist)
    {	# process an administrative request to alter an artist name
	if ($admin_pwd != $admin_pass)
	{   # must have the right password to alter the artist, h4x0r d00d
	    $admin_error = "Admin password is incorrect.";
	}
	else
	{   # correct admin password, update the database
	    # clean up artist names for database
	    if ($slashes)
	    {
		$artist_new = addslashes($artist_new);
		$artist_old = addslashes($artist_old);
	    }

	    # update the database to move all songs with the old artist over to the new artist
	    $res = mysql_query ("update songs set artist='$artist_new' where artist='$artist_old'", $dbi);

	    if (!mysql_affected_rows())
		// mysql_affected_rows appears to flake out with $res as an arg
	    {
		$admin_error = "Database error changing artist '$artist_old' to '$artist_new'";
	    }
	    else
	    {	# change was successful, kill the admin fields
		$admin_artist = 0;
	    }
	}
    }
    

    if ($admin_album_submit and $admin_allow_album)
    {	# process an administrative request to alter an album name
	if ($admin_pwd != $admin_pass)
	{   # must have the right password to alter the album, h4x0r d00d
	    $admin_error = "Admin password is incorrect.";
	}
	else
	{   # correct admin password, update the database
	    # clean up album names for database
	    if ($slashes)
	    {
		$album_new = addslashes($album_new);
		$album_old = addslashes($album_old);
	    }

	    # update the database to move all songs with the old album over to the new album
	    $res = mysql_query ("update songs set album='$album_new' where album='$album_old'", $dbi);

	    if (!mysql_affected_rows())
		// mysql_affected_rows appears to flake out with $res as an arg
	    {
		$admin_error = "Database error changing album '$album_old' to '$album_new'";
	    }
	    else
	    {	# change was successful, kill the admin fields
		$admin_album = 0;
	    }
	}
    }


    if ($admin_songs_submit and $admin_allow_song)
    {	# process an administrative request to alter song details
	if ($admin_pwd != $admin_pass)
	{   # must have the right password to alter songs, h4x0r d00d
	    $admin_error = "Admin password is incorrect.";
	}
	else
	{   # correct admin password, update the database
	    $changed = array();	    # records which songs have changed
	    while (list($k, $v) = each ($songs_songid))
	    {	# find changed songs
		if (preg_match('/[^0-9]/', $songs_order_new[$k]))
		{   # protect against non-numeric order #'s
		    break;
		}
		if (($songs_order_new[$k] != $songs_order_old[$k]) or 
			($songs_title_new[$k] != $songs_title_old[$k]) or 
			($songs_artist_new[$k] != $songs_artist_old[$k]) or 
			($songs_album_new[$k] != $songs_album_old[$k]) or 
			($songs_file_new[$k] != $songs_file_old[$k]))
		{
		    $changed[$k] = $k;	# this song has changed
		}
	    }

	    if (count($changed))
	    {	# some song has changed
		reset($changed);
		while (list($songid, $dummy) = each ($changed))
		{   # update each changed song

		    # clean up song fields for database
		    if ($slashes)
		    {
			$songs_title_new[$songid]  = addslashes($songs_title_new[$songid]);
			$songs_artist_new[$songid] = addslashes($songs_artist_new[$songid]);
			$songs_album_new[$songid]  = addslashes($songs_album_new[$songid]);
			$songs_file_new[$songid]   = addslashes($songs_file_new[$songid]);
		    }

		    # update the changed songs in the database
		    $res = mysql_query ("update songs set 
		    albumposition='$songs_order_new[$songid]', title='$songs_title_new[$songid]', 
		    artist='$songs_artist_new[$songid]', album='$songs_album_new[$songid]', 
		    filename='$songs_file_new[$songid]' where songid=$songid",
		    $dbi); 

		    if (!mysql_affected_rows())
			// mysql_affected_rows appears to flake out with $res as an arg
		    {	# update hosed
			$admin_error = "Database error updating song '$songs_title_old[$k]'";
		    }
		}
	    }

	    if (!$admin_error)
	    {	# we somehow made it through that mess
		$admin_songs = 0;
	    }
	}
    }

    #
    # process player control commands
    #

    if (isset($command))
    {	# a player command was issued
	switch($command)
	{
	    case 'pause':
		# pause the player after the current song
		if ($player_status == 'playing')
		{   # can only pause when playing :-)
		    $res = mysql_query("insert into queue (action, status, origin, player) values (1, 0, '$IP', '$safeplayer')", $dbi);
		    if (mysql_affected_rows())
			// mysql_affected_rows appears to flake out with $res as an arg
		    {	# update vars to reflect new status
			$player_status = 'paused';
			$player_status_when = 0;
			$player_status_IP = $IP;
		    }
		    # otherwise we keep the same status, evidently
		}
	    break;

	    case 'pausenow':
		# pause the player immediately  (same as 'pause' followed by 'skip')
		if ($player_status == 'playing')
		{   # can only pause when playing :-)

		    # issue pause directive to database
		    $res = mysql_query("insert into queue (action, status, origin, player) values (1, 0, '$IP', '$safeplayer')", $dbi);
		    if (mysql_affected_rows())
			// mysql_affected_rows appears to flake out with $res as an arg
		    {	# update vars to reflect new status
			$player_status = 'paused';
			$player_status_when = 0;
			$player_status_IP = $IP;
		    }
		    # and now fall through to "skip" below
		}
	    case 'skip':
		# cut off the currently playing track (if any)
		$res = mysql_query("insert into queue (action, status, player) values (86, 0, '$safeplayer')", $dbi);
		# don't update status.
	    break;

	    case 'play':
		# start the player
		$res = mysql_query("delete from queue where action != 0 and player='$safeplayer'", $dbi);
		if ($res and mysql_affected_rows())
			// mysql_affected_rows appears to flake out with $res as an arg
		{
			$player_status = 'playing';
			$player_status_when = 0;
			$player_status_IP = $IP;
		}
		# otherwise we keep the same status, evidently
	    break;

	    default:
		# unknown command.  don't sweat it.
	    break;
	}
    }


    #
    # get current song information (we do it early to estimate request play times)
    #
    
    $res = mysql_query("select artist, album, title, length,
    (UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(entered)), origin from songs, queue ".
    "where player='$safeplayer' and action = 0 and queue.status = 1 and queue.song = songs.songid", $dbi);

    if ($res and mysql_num_rows($res))
    {	# query succeeded.  wouldn't have it any other way.
	list($current_artist, $current_album, $current_title, $current_length, 
	     $current_played, $current_origin) = mysql_fetch_row($res);
    }
    mysql_free_result($res);		# be nice to the poor little db
    
    #
    # REQUESTS AREA
    #
?>

    <!-- Requests Area -->
      <a name="rdtj_request">
      <table width="99%" cellpadding="0" cellspacing="0" border="0" bgcolor="<?php print $color_request ?>">
        <tr>
	  <td>
	  <?php 
    if ($status_title) 
    {
	  ?>
	    <h2><?php print $status_title ?></h2>
	  <?php 
    } 
	  ?>
	    <h3>
		&nbsp;&nbsp;Player: <?php print $player_desc[$player] ?>
	    </h3>
              <p>
	        <blockquote>
<?php
    # display number of songs in queue
    $res = mysql_query("select count(*) from queue where player='$safeplayer' and action = 0 and status = 0", $dbi);

    if ($res)
    {   # query succeeded
	if (mysql_num_rows($res))
	{   # something in the queue
	    list($count) = mysql_fetch_row($res);
	    print "".($count ? "<b>$count songs in <a href=\"index.php?list=1&player=$player\">request list</a></b><br>\n"
		    : "<b><font color=\"red\">No songs in request list</font></b><br>\n");
	}
	else
	{   # nothing in the queue
	    if ($player_action[$player] == 1)
	    {
	        print "<b><font color=\"green\">Playing random songs from collection</font></b><br>";
	    }
	    else
	    {
	        print "<b><font color=\"red\">No songs in request list</font></b><br>\n";
	    }
	}
    }
    else
    {   # query bombed!  bummer.
	print "<h1>Unable to retrieve status, ya punk!</h1>\n";
    }

    mysql_free_result($res);	# be nice to the poor little db

    #
    # FULL request list
    #

    if (isset($list) and $count > 0)
    {	# they actually want to see the full request list
	$res = mysql_query("select artist, album, title, length, qid,
	(UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(entered)), origin from songs,
	queue where queue.player='$safeplayer' and queue.action = 0 and queue.status = 0 and queue.song =
	songs.songid order by qid", $dbi);
	if ($res)
	{   # query succeeded... at least we have that going for us, which is nice
	    if (mysql_num_rows($res))
	    {   # and there's something in the request list
		# output request list
		if ($request_title)
		{
		    print "        <h3>$request_title</h3>\n";
		}
		if ($mass_murder)
		{
		?>
		<form method="post" action="index.php">
		<input type="hidden" name="player" value="<?php print $player ?>">
		<?php
		}
		?>
		    <ol>
		<?php
		# initialize running total for "how long until this plays" :-)
		$running_until = $current_length - $current_played;

		while (list ($artist, $album, $title, $length, $qid, $wait, $ip) = mysql_fetch_row($res))
		{
		    # build request line
		    $song_line = '<li> ';
		    
		    # add optional mass-delete button
		    $song_line .= 
			($mass_murder ? '<input type="checkbox" name="kill['.$qid.']" value="off"> ' : '');
		    
		    # optional kill links
		    $song_line .= ($request_kill ?
			" <font size=\"-1\">(<a href=\"index.php?kill=$qid&list=1&player=$player\">kill</a>)</font> " : '');
			
		    # add song information
		    $song_line .= "\"$title\"";

		    # add optional track length
		    if ($request_length)
		    {
			$timestring = readable_time($length);
			$song_line .= " [$timestring]";
		    }
		    
		    # add artist information
		    $song_line .= " by $artist";
		    
		    # add album information
		    $song_line .= ($request_album ? " from <i>$album</i>" : '');
		    
		    # add "how long until this plays" info
		    if ($player_status == 'playing')
		    {
			$song_line .= "<br><font size=\"-1\">plays in ".readable_time($running_until)."</font>\n";
		    }

		    # optional display of time in queue
		    if ($request_wait_time or $request_show_IP)
		    {
			$waitstring = readable_time($wait);
			$song_line .= '<font size="-1">(requested'. 
			    ($request_wait_time ? " $waitstring ago" : '').
			    ($request_show_IP   ? " from $ip" : '').') ';
			# may need to close font tag
			$song_line .= '</font>';
		    }

		    $song_line .= ' <font color="red">'.readable_time($running_until+$length).' total</font>';

		    # close formatting
		    $song_line .= "</li>\n";

		    # and send it...
		    print $song_line;

		    # update running "how long until this plays" total
		    $running_until += $length;
		}
		?>
		    </ol>
		<?php
		if ($mass_murder)
		{   # submit button and close form if allowing mass-kill
		?>
		    <input type="submit" value="Kill selected">
		</form>
		<?php
		}

		reset($players);

		# "move to" links
		while (list($k, $play) = each($players))
		{
		    if ($play != $player)
		    {
		    ?>
			<a href="index.php?player=<?php 
				print $play ?>&mfrom=<?php 
				print $player ?>&mto=<?php 
				print $play ?>">move songs to <?php 
				print $player_desc[$play] ?><br>
		    <?php
		    }
		}

		if ($request_close)
		{
		    ?>
			<center>
			    <a href="index.php?songs_closed=1&player=<?php print $player ?>">Hide requests</a>
			</center>
		    <?php
		}
	    }
	}
	else
	{   # query failed.  lousy query!
	    print "<h1>Unable to retrieve request list... ouch.</h1>\n";
	}
	mysql_free_result($res);	# be nice to the poor little db
    }
?>
	      </blockquote>
	    </p>
	  </td>
        </tr>
      </table>
      
    <!-- Current Song Area -->
      <a name="rdtj_status">
      <table cols="2" width="99%" cellpadding="0" cellspacing="0" border="0" bgcolor="<?php print $color_status ?>">
        <tr>
	  <td>
             <p>
	       <blockquote>
<?php

    #
    #	CURRENT SONG AREA
    #

    # display currently playing song
    if ($player_status == 'playing' and $current_title)
    {   # there is a song currently playing

	# prepare output line
	$song_line = "<br><b><p>$status_play_line</p>\"$current_title\"";

	# add optional song length
	if($status_length)
	{
	    $timestring = readable_time($current_length);
	    $song_line .= " [$timestring]";
	}

	# add artist name
	$song_line .= "<br> by $current_artist<br> ";

	# add optional album info
	$song_line .= ($status_album ?  "from <i>$current_album</i> " : '');

	# add optional IP address 
	$song_line .= ($status_show_IP ? "<br><font size=\"-1\">(requested from $current_origin)</font>" : '');

	# close formatting
	$song_line .= "</b><br>\n";

	# and output the line
	print $song_line;
    }
    else
    {	# must print something to keep the status area colored :-(
	print "&nbsp;\n";
    }
    ?>
	      </blockquote>
	    </p>
	  </td>
    <?php
    ?>
    <?php

    if ($controls_on)
    {
    ?>
	  <!-- player controls area -->

	  <td width="50%" align="left" bgcolor="<?php print $color_controls ?>">
	    <blockquote>
	  <?php
	  if ($player_status == 'playing')
	  {
	     if ($current_length)
	     {
		# compute time remaining
		$remaining = $current_length - $current_played;
		$remaining = ($remaining < 0 ? 0 : $remaining);
		$remainstring = "[".readable_time ($remaining)."]";
	  ?>
		(Playing - <?php print $remainstring ?> remaining) <br><br>
	  <?php
	      }
	      else
	      {
	  ?>
		(Playing) <br><br>
	  <?php
	      }
	  ?>
		<a href="index.php?player=<?php print $player ?>&command=pause">Stop player after current song</a><br>
		<a href="index.php?player=<?php print $player ?>&command=pausenow">Stop player immediately</a><br>
		<a href="index.php?player=<?php print $player ?>&command=skip">Skip currently playing song</a><br>
		<a href="index.php?player=<?php print $player ?>&defaction=<?php 
		    print ''.($player_action[$player] == 1 ? 0 : 1) ?>">Turn random songs <?php 
		    print ''.($player_action[$player] == 1 ? 'off' : 'on') ?></a><br>
	  <?php
	  }
	  if ($player_status == 'paused')
	  { 
	      print "(Paused"; 
	      if ($controls_show_time)
	      {
		  print " for ".readable_time($player_status_when);
	      }
	      if ($controls_show_IP)
	      {
		  print " by $player_status_IP";
	      }
	      print ")";
	  ?>
		<br>
		<a href="index.php?player=<?php print $player ?>&command=play">Start player</a><br>
	  <?php
	  }
	  if ($player_status == 'updating')
	  {
	    print "(synchronizing player)\n";
	  }
	  ?>
	  <br>
	  <?php

	  reset($players);

	  while (list($k, $play) = each($players))
	  {
	    if ($play != $player)
	    {
	      ?>
		  <a href="index.php?player=<?php print $play ?>">go to 
		  <?php print $player_desc[$play] ?><br>
	      <?php
	    }
	  }
    }
    ?>
	    </blockquote>
	  </td>
        </tr>
      </table>

	    <!-- begin "greatest hits" list -->

      <a name="rdtj_hits">
      <table width="99%" cellpadding="0" cellspacing="0" border="0" bgcolor="<?php print $color_hits ?>">
        <tr>
	  <td>
<?php

    #
    #	GREATEST HITS AREA
    #

    if (isset($hits) and !preg_match("/[^0-9]/", $hits))
    {   # user wants to see the greatest hits list
	# first, collect the greatest hits data
	$res = mysql_query("select title, artist, album, count(*) as hits
		from songs, queue where action = 0 and status=2 and songid=song group by song, artist, album
		order by hits desc limit $hits", $dbi);

	# now, display it
	if ($res)
	{	# query succeeded.  wouldn't have it any other way.
	    if (mysql_num_rows($res))
	    {   # there be some hits, G
		if ($hits_title)
		{
		    print "     <br><h2>$hits_title</h2>\n";
		}
		print "	        <blockquote>\n";
		print "		  <ol>\n";
		$total = 0;
		while (list($title, $artist, $album, $songhits) = mysql_fetch_row($res))
		{   # print out each hit line
		    print "	       <li> ($songhits) <b>$title</b> by $artist from <i>$album</i></li>\n";
		    $total++;
		}
		print "		  </ol>\n";
		print "	        </blockquote><br>\n";

		if ($hits_close)
		{   # print "close hits" link
		?>
		    <center>
		       <a href="index.php?player=<?php print $player ?>">Hide greatest hits</a><br>
		    </center>
		<?php
		}

		if ($total == $hits)
		{   # allow links for more hits
		?>
		    <center>
			View Greatest hits up to:
			    <a href="index.php?player=<?php print $player ?>&hits=<?php print 2*$hits ?>#rdtj_hits"><?php print 2*$hits ?> hits</a>,
			    <a href="index.php?player=<?php print $player ?>&hits=<?php print $hits_all ?>#rdtj_hits">all</a>
		    </center>
		<?php
		}
	    }
	    else
	    {   # no hits
		print "<b>No hits.</b><br>\n";
	    }
	    mysql_free_result($res);	# be nice to the poor little db
	}
	else
	{   # query failed.  shit.
	    print "<h1>Unable to retrieve greatest hits.  Doomed to mediocrity.</h1>\n";
	}

    }
    else
    {   # just provide a greatest hits link
    ?>
	      <center>
		 <a href="index.php?player=<?php print $player ?>&hits=<?php print $hits_default ?>#rdtj_hits">Show greatest hits</a>
	      </center>
    <?php
    }
?>	    
	  </td>
        </tr>
      </table>

      <a name="rdtj_songs">

      <!-- begin artist/album listings -->
<?php

	#
	#   SONG LISTING AREA
	#

	if ($songs_closed)
	{   # user asked to hide the songs list
	    ?>
	    <table width="99%" cellpadding="0" cellspacing="0" border="0" bgcolor="<?php print $color_songs ?>">
	      <tr>
	        <td align="center">
		<a href="index.php?player=<?php print $player ?>#rdtj_songs">Show songs</a>
		</td>
	      </tr>
	    </table>
	    <?php
	    # don't even bother with the rest of the code... we're done
	    include "./footer.php";
	    return;
	}
	?>
	    <center>
	      <font size="-1">
		<a href="index.php?player=<?php print $player ?>&songs_closed=1<?php print ($list?'&list=1':'') ?>">Hide songs</a>
	      </font>
	    </center>
	<?php

	if ($songs_title)
	{
	    print "       <h2>$songs_title</h2>\n";
	}

	# get a list of songs in the queue for marking purposes
	$queued_songs = array();    # hash of requested songs
	$playing = 0;		    # the currently requested song
	$res = mysql_query("select distinct song, status from queue where player='$safeplayer' and action = 0 and status in (0,1)", $dbi);
	if ($res and mysql_num_rows($res))
	{
	    while (list($song, $status) = mysql_fetch_row($res))
	    {
		if ($status) { $playing = $song; }
		else { $queued_songs[$song] = 1; }
	    }
	}   # otherwise, don't bother
	mysql_free_result($res);	# be nice to the poor little db

	# generate a sorted list of all the artists and albums available
	$res = mysql_query("select distinct artist, album from songs order by artist, album", $dbi);

	# output the list with links, find songs when necessary
	if ($res)
	{   # album/artist query successful
	    $album_artist = array();	    # to save the ordered album/artist list
	    if (mysql_num_rows($res))
	    {	# well, it's good that you own some mp3's you cheap bastard!
		# save the album/artist info
		while (list($artist, $album) = mysql_fetch_row($res)) 
		{ 
		    $album_artist[] = array("artist" => $artist, "album" => $album);
		}
		mysql_free_result($res);	# be nice to the poor little db

		# now go through the album/artist list and output page
		# when we hit the album/artist to expand get the songs and expand

		# clear album/artist traversal state vars
		$prevartist = $prevalbum = '';  $inartist = $inalbum = 0;

		reset($album_artist);
		while (list($key, $value) = each($album_artist))
		{   # handle a single artist/album pair
		    $artist = $value['artist'];
		    $album = $value['album'];

		    # generate link tag for the new album
		    $tag = "$artist$album";
		    $tag = preg_replace("/[^A-Z0-9]/i", "_", $tag);

		    # generate link tag for the new artist
		    $atag = $artist;
		    $atag = preg_replace("/[^A-Z0-9]/i", "_", $atag);

		    if ($artist != $prevartist)
		    {	# a new artist encountered
			$prevartist = $artist;

			if ($inalbum)
			{   # close any open album nesting
			    if ($listing_songs and $admin_allow_song and $admin_songs)
			    {	# handle case where songs were under administration
			    ?>
					<tr>
					  <td align="right">
					    Admin password:
					  </td>
					  <td align="left">
					    <input type="password" name="admin_pwd">
					  </td>
					<td align="left"><input type="submit" name="admin_songs_submit" value="change"</td>
					</tr>
				       </table>
				       </td></tr></table>
				    </form>
			    <?php	
			    }

			    if ($albumlist)
			    { ?>
			    <br><a href="index.php?player=<?php print $player
			    ?>&submit=<?php print $albumlist ?>">(play entire album)</a>
			    <?php
			    }
			    print "</blockquote>\n";
			}
			$inalbum = 0;
			$listing_songs = 0;	# clear song expand flag

			# close any open artist nesting
			print "".($inartist? "</blockquote>\n" : '');

			print "<a name=\"$atag\">";
			if ($expand == $atag)
			{   # we were instructed to show songs for this artist
			    # provide optional administration interface for artist info
			    if ($admin_allow_artist)
			    {
				if (!$admin_artist)
				{   # create "modify" link for this artist
				    print "<a href=\"index.php?player=$player&expand=$atag&admin_artist=1#$atag\">!</a>\n";
				    print "<b>$artist</b>\n";
				}
				else
				{   # "modify" link has already been clicked!
				    $htmlartist = preg_replace('/"/', '&qout;', $artist);
				    if ($admin_error)
				    { ?>
					<b><font color="red">Error: <?php print $admin_error ?></font></b>
				    <?php
				    }
				    ?>
				    <form method="post" action="index.php#<?php print $atag ?>">
				      <input type="hidden" name="player" value="<?php print $player ?>">
				      <input type="hidden" name="admin_artist" value="1">
				      <input type="hidden" name="expand" value="<?php print $atag ?>">
				      <input type="hidden" name="artist_old" value="<?php print $htmlartist ?>">
				      <table width="40%"><tr><td align="left">
				      <table border="0" cols="2">
				        <tr>
					  <td align="right">
					    New artist name: 
					  </td>
					  <td align="left">
					    <input type="text" name="artist_new" value="<?php print $htmlartist ?>">
					  </td>
					</tr>
					<tr>
					  <td align="right">
					    Admin password:
					  </td>
					  <td align="left">
					    <input type="password" name="admin_pwd">
					  </td>
					</tr>
					<tr>
					<td>&nbsp;</td>
					<td align="right"><input type="submit" name="admin_artist_submit" value="change"</td>
					</tr>
				       </table>
				       </td></tr></table>
				    </form>
				    <?php
				}
			    }
			    else
			    {	# artist administration is turned off
				# don't print an expand link for this artist
				print "<b>$artist</b>\n";
			    }
			}
			else
			{   # print an expand link for this artist
			    if ($expand == $tag and $admin_allow_artist)
			    {
				print "<a href=\"index.php?player=$player&expand=$atag&admin_artist=1#$atag\">!</a>\n";
			    }
			    print "<b><a href=\"index.php?player=$player&expand=$atag#$atag\">$artist</a></b>\n";
			}

			# start a new artist nesting
			print "<blockquote>\n";

			# set flag to denote opened artist nesting
			$inartist = 1;
		    }

		    if ($album != $prevalbum)
		    {	# a new album encountered
			$prevalbum = $album;
			if ($inalbum)
			{   # close any open album nesting
			    if ($listing_songs and $admin_allow_song and $admin_songs)
			    {	# handle case where songs were under administration
			    ?>
					<tr>
					  <td align="right">
					    Admin password:
					  </td>
					  <td align="left">
					    <input type="password" name="admin_pwd">
					  </td>
					<td align="left"><input type="submit" name="admin_songs_submit" value="change"</td>
					</tr>
				       </table>
				       </td></tr></table>
				    </form>
			    <?php	
			    }

			    if ($albumlist)
			    {	# output "play album" link if available
			    ?>
			    <br><a href="index.php?player=<?php print $player ?>&submit=<?php print $albumlist ?>">(play entire album)</a>
			    <?php
			    }

			    #close formatting
			    print "</blockquote>\n";
			}
			$inalbum = 0;		# no longer in an album
			$listing_songs = 0;	# clear song expand flag
			$albumlist = '';	# clear running list of songs for album play

			print "<a name=\"$tag\">";
			if ($expand == $tag)
			{   # we were instructed to show songs for this album
			    # provide optional administration interface for album info
			    if ($admin_allow_album)
			    {
				if (!$admin_album)
				{   # create "modify" link for this album
				    print "<a href=\"index.php?player=$player&expand=$tag&admin_album=1#$tag\">!</a>\n";
				    print "<b>$album</b>\n";
				}
				else
				{   # "modify" link has already been clicked!
				    $htmlalbum = preg_replace('/"/', '&qout;', $album);
				    if ($admin_error)
				    { ?>
					<b><font color="red">Error: <?php print $admin_error ?></font></b>
				    <?php
				    }
				    ?>
				    <form method="post" action="index.php#<?php print $tag ?>">
				      <input type="hidden" name="player" value="<?php print $player ?>">
				      <input type="hidden" name="admin_album" value="1">
				      <input type="hidden" name="expand" value="<?php print $tag ?>">
				      <input type="hidden" name="album_old" value="<?php print $htmlalbum ?>">
				      <table width="40%"><tr><td align="left">
				      <table border="0" cols="2">
				        <tr>
					  <td align="right">
					    New album name: 
					  </td>
					  <td align="left">
					    <input type="text" name="album_new" value="<?php print $htmlalbum ?>">
					  </td>
					</tr>
					<tr>
					  <td align="right">
					    Admin password:
					  </td>
					  <td align="left">
					    <input type="password" name="admin_pwd">
					  </td>
					</tr>
					<tr>
					<td>&nbsp;</td>
					<td align="right"><input type="submit" name="admin_album_submit" value="change"</td>
					</tr>
				       </table>
				       </td></tr></table>
				    </form>
				    <?php
				}
			    }
			    else
			    {	# album administration is turned off
				# don't print an expand link for this album
				print "<b>$album</b>\n";
			    }
			}
			else
			{   # print an expand link for this album
			    if ($expand == $atag and $admin_allow_album)
			    {
				print "<a href=\"index.php?player=$player&expand=$tag&admin_album=1#$tag\">!</a>\n";
			    }
			    print "<b><a href=\"index.php?player=$player&expand=$tag#$tag\">$album</a></b>\n";
			}

			# start a new album nesting
			print "<blockquote>\n";

			# set flag to denote opened album nesting
			$inalbum = 1;
		    }

		    if ($expand == $tag or $expand == $atag)
		    {	# if this album/artist is expanded then output song play link
			# protect db query if necessary
			$dbalbum = ($slashes ? $album : addslashes($album));
			$dbartist = ($slashes ? $artist : addslashes($artist));

			$firstsong = 1;	    # flag for whether we're on the first song

			# retrieve songs for this album
			$res = mysql_query("select songid, title, length,
			albumposition, filename from songs where
			album='$dbalbum' and artist='$dbartist' order by
			artist, album, albumposition", $dbi);

			if ($res and mysql_num_rows($res))
			{
			    while (list ($songid, $title, $length, $albumposition, $filename) = mysql_fetch_row($res))
			    {
				$timestring = readable_time($length);

				# this flag must be set to close formatting/forms elsewhere
				$listing_songs = 1;

				# provide song administration interface
				#
				# this interface is different from the others since we
				# want to be able to reorder songs on the album as well
				# as edit their information...
			
				if ($admin_allow_song)
				{
				    if (!$admin_songs)
				    {	# provide link for song administration, normal links
					if ($firstsong)
					{   # only print the admin link with the first song
					    print "<a href=\"index.php?player=$player&expand=$tag&admin_songs=1#$tag\">!</a>\n";
					}
					else
					{   # keep the spacing (hopefully) consistent
					    print "&nbsp;&nbsp;";
					}

					# print a regular song entry
					print "<a href=\"index.php?player=$player&submit=$songid&expand=$expand#$tag\">[$timestring] $title</a>".
					    ($queued_songs[$songid] ? " [<i>requested</i>]" : '').
					    (($playing == $songid) ? " [<i>playing</i>]" : '')."<br>\n";
				    }
				    else
				    {	# already clicked the admin link
					if ($firstsong)
					{   # start the admin form before the first song
					    if ($admin_error)
					    { ?>
						<b><font color="red">Error: <?php print $admin_error ?></font></b>
						    <?php
					    }
					    ?>
					    <form method="post" action="index.php#<?php print $atag ?>">
					    <input type="hidden" name="player" value="<?php print $player ?>">
					    <input type="hidden" name="admin_songs" value="1">
					    <input type="hidden" name="expand" value="<?php print $expand ?>">
					    <table width="40%"><tr><td align="left">
					    <table border="0" cols="5">
					    <tr>
					    <td align="right">
					    #
					    </td>
					    <td>
					    Title 
					    </td>
					    <td>
					    Artist 
					    </td>
					    <td>
					    Album 
					    </td>
					    <td>
					    File 
					    </td>
					    <?php

					}
					# create the admin widgets for this song entry

					# protect text fields from double quotes (")
					$htmlalbumposition = preg_replace('/"/', '&quot;', $albumposition);
					$htmltitle = preg_replace('/"/', '&quot;', $title);
					$htmlartist = preg_replace('/"/', '&quot;', $artist);
					$htmlalbum = preg_replace('/"/', '&quot;', $album);
					$htmlfilename = preg_replace('/"/', '&quot;', $filename);
				    ?>
					<input type="hidden" name="songs_songid[<?php print $songid ?>]" value="<?php print $songid ?>">
					<tr>
					<td align="right">
					    <input type="text" name="songs_order_new[<?php print $songid ?>]" 
						size="3" value="<?php print $htmlalbumposition ?>">
					    <input type="hidden" name="songs_order_old[<?php print $songid ?>]" 
								value="<?php print $htmlalbumposition ?>">
					  </td>
					  <td>
					    <input type="text" name="songs_title_new[<?php print $songid ?>]" 
							       value="<?php print $htmltitle ?>">
					    <input type="hidden" name="songs_title_old[<?php print $songid ?>]" 
							       value="<?php print $htmltitle ?>">
					  </td>
					  <td>
					    <input type="text" name="songs_artist_new[<?php print $songid ?>]" 
							       value="<?php print $htmlartist ?>">
					    <input type="hidden" name="songs_artist_old[<?php print $songid ?>]" 
							       value="<?php print $htmlartist ?>">
					  </td>
					  <td>
					    <input type="text" name="songs_album_new[<?php print $songid ?>]" 
							       value="<?php print $htmlalbum ?>">
					    <input type="hidden" name="songs_album_old[<?php print $songid ?>]" 
							       value="<?php print $htmlalbum ?>">
					  </td>
					  <td>
					    <input type="text" name="songs_file_new[<?php print $songid ?>]" 
							       value="<?php print $htmlfilename ?>">
					    <input type="hidden" name="songs_file_old[<?php print $songid ?>]" 
							       value="<?php print $htmlfilename ?>">
					  </td>
					</tr>
				    <?php
				    }
				}
				else
				{
				    # print a regular song entry
				    print "<a href=\"index.php?player=$player&submit=$songid&expand=$expand#$tag\">[$timestring] $title</a>".
					($queued_songs[$songid] ? " [<i>requested</i>]" : '').
					(($playing == $songid) ? " [<i>playing</i>]" : '')."<br>\n";
				}

				$firstsong = 0;	    # clear first song flag

				# update album play list
				$albumlist .= ($albumlist ? ',':'').$songid;
			    }
			    mysql_free_result($res);	# be nice to the poor little db
			}
		    }
		}


		if ($inalbum)
		{   # close any open album nesting
		    if ($listing_songs and $admin_allow_song and $admin_songs)
		    {	# handle case where songs were under administration
			?>
			    <tr>
			    <td align="right">
			    Admin password:
			    </td>
			    <td align="left">
			    <input type="password" name="admin_pwd">
			    </td>
			    <td align="left"><input type="submit" name="admin_songs_submit" value="change"</td>
			    </tr>
			    </table>
			    </td></tr></table>
			    </form>
			    <?php	
		    }

		    if ($albumlist)
		    {	# output "play album" link if available
			?>
			    <br><a href="index.php?player=<?php print $player ?>&submit=<?php print $albumlist ?>">(play entire album)</a>
			    <?php
		    }
		    #close formatting
		    print "</blockquote>\n";
		}

		# close any open artist nesting
		print "".($inartist? "</blockquote>\n" : '');
	    }
	    else
	    {	# no songs in your collection, cheapskate
		?>
		    <b>No songs found</b>
		<?php
	    }
	}
	else
	{   # query failed!
	    ?>
		<h1><font color="red">Unable to retrieve song list -- call yer mom!</font</h1>
	    <?php
	}

	# include HTML footer
	include "./footer.php";
?>
