CREATE TABLE roman140176_redirects_rule (
    ID int NOT NULL AUTO_INCREMENT,
    SITE_ID char(2) NOT NULL,
    ACTIVE char(1) NOT NULL DEFAULT 'Y',
    SOURCE varchar(255) NOT NULL,
    SOURCE_KEY varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    TARGET varchar(500) NOT NULL,
    STATUS smallint NOT NULL DEFAULT 301,
    HITS int NOT NULL DEFAULT 0,
    LAST_HIT_AT datetime NULL,
    CREATED_AT datetime NOT NULL,
    PRIMARY KEY (ID),
    UNIQUE KEY ux_roman140176_redirects_rule_source (SITE_ID, SOURCE_KEY)
);

CREATE TABLE roman140176_redirects_404 (
    ID int NOT NULL AUTO_INCREMENT,
    SITE_ID char(2) NOT NULL,
    URL varchar(500) NOT NULL,
    URL_HASH char(32) NOT NULL,
    REFERER varchar(500) NULL,
    HITS int NOT NULL DEFAULT 1,
    FIRST_AT datetime NOT NULL,
    LAST_AT datetime NOT NULL,
    PRIMARY KEY (ID),
    UNIQUE KEY ux_roman140176_redirects_404_url (SITE_ID, URL_HASH),
    KEY ix_roman140176_redirects_404_last (LAST_AT)
);
