<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$s = "\xC4pfel";
$enc = utf8_encode($s);
$dec = utf8_decode($enc);
echo bin2hex($enc), "|", bin2hex($dec), "|", strlen($enc), "|", strlen($dec), "\n";
echo utf8_encode(""), "|", bin2hex(utf8_decode("\xC3\x84")), "\n";
