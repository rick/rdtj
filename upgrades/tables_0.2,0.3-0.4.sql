alter table queue add column player varchar(32);
drop table if exists player;
create table player
(
        name            varchar(32) not null primary key,
        message         varchar(100),
	defaultaction	int(5) not null
);
