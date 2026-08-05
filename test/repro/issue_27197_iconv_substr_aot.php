<?php
// Issue #27197 — AOT iconv_substr literal + dynamic string / literal ints
echo iconv_substr('abcdef', 1, 3, 'UTF-8'), "\n";
$s = 'abcdef';
echo iconv_substr($s, 1, 3, 'UTF-8'), "\n";
