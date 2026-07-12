--TEST--
AOT strtok() null haystack with delimiter arg returns false (#5515, php-src-strict)
--FILE--
<?php
$s = 'a,b,c';
echo strtok($s, ','), '|';
echo strtok(null, ',') === false ? '' : 'bad', "\n";
--EXPECT--
a|
