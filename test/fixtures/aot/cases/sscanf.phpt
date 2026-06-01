--TEST--
AOT: sscanf() integer out-arg (issue #3190)
--FILE--
<?php
$n = 0;
sscanf('42', '%d', $n);
echo $n;
--EXPECT--
42
