--TEST--
Web: missing $_GET key yields null (Zend warning when error_reporting includes E_WARNING)
--ENV--
QUERY_STRING=
--FILE--
<?php
$v = $_GET['name'];
echo $v === null ? "null\n" : "set\n";
--EXPECT--
null
