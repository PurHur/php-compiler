<?php
/**
 * AOT: SQLite3::version leftover of escapeString (#35977 / #35991).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3_version
 */
var_export(SQLite3::version());
echo PHP_EOL;
$db = new SQLite3(':memory:');
var_export($db->version());
echo PHP_EOL;
