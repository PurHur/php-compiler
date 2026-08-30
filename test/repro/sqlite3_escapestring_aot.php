<?php
/**
 * AOT: SQLite3::escapeString leftover of busyTimeout (#35972 / #35931).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3_escapeString / sqlite3_mprintf("%q")
 */
var_dump(SQLite3::escapeString("a'b"));
var_dump(SQLite3::escapeString('plain'));
var_dump(SQLite3::escapeString(''));
$db = new SQLite3(':memory:');
var_dump($db->escapeString("x'y"));
