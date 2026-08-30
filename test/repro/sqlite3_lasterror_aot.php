<?php
/**
 * AOT: SQLite3 lastErrorCode/lastErrorMsg leftover of lastInsertRowID (#35966 / #35931).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3_lastErrorCode / zim_SQLite3_lastErrorMsg
 */
$db = new SQLite3(':memory:');
var_dump($db->lastErrorCode());
var_dump($db->lastErrorMsg());
