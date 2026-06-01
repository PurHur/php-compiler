--TEST--
AOT: quoted_printable_encode() / quoted_printable_decode()
--FILE--
<?php
$raw = "foo bar\r\n";
$enc = quoted_printable_encode($raw);
echo (quoted_printable_decode($enc) === $raw) ? "1" : "0";
--EXPECT--
1
