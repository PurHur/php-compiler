--TEST--
language: echo null (issue #71, empty string like VM)
--FILE--
<?php
$n = null;
echo '|', $n, "|\n";
echo '|', null, "|\n";
--EXPECT--
||
||
