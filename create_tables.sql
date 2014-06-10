CREATE TABLE dxstation(
	id smallint unsigned NOT NULL auto_increment,
	dxcallsign varchar(30) NOT NULL,
	ordering smallint unsigned NOT NULL, 
	PRIMARY KEY (id)
);

CREATE TABLE qsos(
	id mediumint unsigned NOT NULL auto_increment,
	callsign varchar(12) NOT NULL,
	op_mode varchar(6) NOT NULL,
	band decimal(10,5) unsigned NOT NULL,
	fk_dxstn smallint unsigned NOT NULL,
	PRIMARY KEY (id),
	KEY (callsign, fk_dxstn)
);

CREATE TABLE logfiles(
	id mediumint unsigned NOT NULL auto_increment,
	filename varchar(255) NOT NULL,
	qsos smallint unsigned NOT NULL,
	filetype varchar(20) NOT NULL,
	loaded timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id)
);
