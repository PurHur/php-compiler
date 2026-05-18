--TEST--
stdlib array_key_exists() on compile-time $_GET hashtable
--ENV--
QUERY_STRING=name=Ada
--FILE--
<?php
echo array_key_exists('name', $_GET) ? 'y' : 'n', "\n";
echo array_key_exists('missing', $_GET) ? 'y' : 'n', "\n";
--EXPECT--
y
n
