--TEST--
AOT: $_GET['name'] ?? default when key is present (issues #99, #148)
--GET--
name=Ada
--FILE--
<?php
echo $_GET['name'] ?? 'Guest', "\n";
--EXPECT--
Ada
--EXPECT_EXIT--
0
