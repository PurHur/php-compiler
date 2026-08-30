<?php
/**
 * AOT: SQLite3::busyTimeout leftover of lastError (#35966 / #35931).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3_busyTimeout
 */
$db = new SQLite3(':memory:');
var_dump($db->busyTimeout(1000));
var_dump($db->lastErrorCode());
