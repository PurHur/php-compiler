--TEST--
Web: empty() on missing and present $_GET keys
--ENV--
QUERY_STRING=name=Ada&flag=
--FILE--
<?php
echo empty($_GET['missing']) ? 'missing-y' : 'missing-n', "\n";
echo empty($_GET['name']) ? 'name-y' : 'name-n', "\n";
echo empty($_GET['flag']) ? 'flag-y' : 'flag-n', "\n";
--EXPECT--
missing-y
name-n
flag-y
