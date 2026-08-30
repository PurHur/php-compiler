<?php
/**
 * AOT: SQLite3::version leftover of escapeString (#35991 / #35977).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3_version
 */
$v = SQLite3::version();
echo $v['versionString'], "\n";
echo $v['versionNumber'], "\n";
echo implode(',', array_keys($v)), "\n";
$db = new SQLite3(':memory:');
$w = $db->version();
echo ($v === $w) ? "same\n" : "diff\n";
echo $w['versionString'], "\n";
