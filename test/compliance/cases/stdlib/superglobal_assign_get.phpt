--TEST--
Web: assign then read $_GET key (issue #103)
--FILE--
<?php
$_GET['x'] = '1';
echo $_GET['x'], "\n";
--EXPECT--
1
