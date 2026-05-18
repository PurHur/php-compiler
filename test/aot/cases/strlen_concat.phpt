--TEST--
AOT: strlen on concatenated string
--FILE--
<?php
$s = 'ab' . 'cd';
echo strlen($s), "\n";
--EXPECT--
4
