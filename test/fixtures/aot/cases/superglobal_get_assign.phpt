--TEST--
AOT: runtime write to $_GET then read (issue #103)
--ENV--
QUERY_STRING=
--FILE--
<?php
$_GET['debug'] = 'on';
echo $_GET['debug'], "\n";
--EXPECT--
on
