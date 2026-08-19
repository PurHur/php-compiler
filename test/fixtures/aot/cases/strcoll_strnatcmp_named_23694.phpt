--TEST--
AOT: strcoll/strnatcmp named Zend stub params (#23694)
--FILE--
<?php
echo strcoll(string1: 'a1', string2: 'a2'), "\n";
echo strnatcmp(string1: 'a1', string2: 'a2'), "\n";
echo strnatcasecmp(string1: 'A1', string2: 'a2'), "\n";
--EXPECT--
-1
-1
-1
