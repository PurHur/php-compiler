--TEST--
stdlib utf8_encode()/utf8_decode() scalar coercion JIT (#4317)
--FILE--
<?php
echo utf8_encode(65), "\n";
echo utf8_decode(195), "\n";
echo utf8_encode(true), "\n";
--EXPECT--
65
195
1
