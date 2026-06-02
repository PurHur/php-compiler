--TEST--
stdlib quoted_printable_encode() / quoted_printable_decode()
--FILE--
<?php
echo function_exists('quoted_printable_encode') ? '1' : '0';
echo function_exists('quoted_printable_decode') ? '1' : '0';
echo "\n";
$raw = "foo bar\r\n";
$enc = quoted_printable_encode($raw);
echo $enc;
echo quoted_printable_decode($enc);
echo "---\n";
$soft = "long line that should wrap with soft break at seventy six characters exactly here!!\n";
$enc2 = quoted_printable_encode($soft);
echo (quoted_printable_decode($enc2) === $soft) ? "soft-ok\n" : "soft-fail\n";
echo bin2hex(quoted_printable_encode('')), "\n";
--EXPECT--
11
foo bar
foo bar
---
soft-ok

