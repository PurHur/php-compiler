--TEST--
AOT: str_replace scalar strings return string not Array (#23912)
--FILE--
<?php
$s = "hello world";
echo str_replace("o", "0", $s), "\n";
echo str_replace("o", "0", "hello world"), "\n";
--EXPECT--
hell0 w0rld
hell0 w0rld
