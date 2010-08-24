drop table if exists songs;
create table songs
(
	songid		int(10) unsigned not null auto_increment primary key,
	title		varchar(128) not null, 
	artist		varchar(128),
	album		varchar(128),
	length		int(10) unsigned,
	albumposition	int(5)	unsigned,
	filename	varchar(255) not null,
	lastmodified	timestamp
);

drop table if exists queue;
create table queue
(
	qid		int(10) unsigned not null auto_increment primary key,
	action		int(5) unsigned not null,
	entered		timestamp,
	status		int(3) unsigned not null,
	song		int(10) unsigned not null,
	name		varchar(32),
	origin		varchar(128),
	player		varchar(32)
);

drop table if exists player;
create table player
(
	name		varchar(32) not null primary key,
	message		varchar(100),
	defaultaction	int(5) unsigned not null
);
