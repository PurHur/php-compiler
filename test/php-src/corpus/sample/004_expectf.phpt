--TEST--
sample: EXPECTF %d
--FILE--
<?php
echo "n=", strlen("abcd"), "\n";
?>
--EXPECTF--
n=%d
