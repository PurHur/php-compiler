--TEST--
AOT utf8_encode()/utf8_decode() scalar coercion (#4317)
--FILE--
<?php
echo utf8_encode(65), "\n";
echo utf8_decode(195), "\n";
--EXPECT--
65
195
