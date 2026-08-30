<?php
/**
 * AOT: SQLite3 enableExceptions leftover of busyTimeout (#35975 / #35972).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3_enableExceptions
 */
$db = new SQLite3(':memory:');
var_dump($db->enableExceptions(true));
var_dump($db->enableExceptions(false));
var_dump($db->enableExceptions());
