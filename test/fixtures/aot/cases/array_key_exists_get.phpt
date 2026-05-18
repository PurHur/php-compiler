--TEST--
AOT: array_key_exists() on compile-time $_GET
--ENV--
QUERY_STRING=name=Compiled
--FILE--
<?php
echo array_key_exists('name', $_GET) ? "yes\n" : "no\n";
echo array_key_exists('other', $_GET) ? "yes\n" : "no\n";
--EXPECT--
yes
no
