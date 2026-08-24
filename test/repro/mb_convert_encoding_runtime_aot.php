<?php
// AOT NestedJIT mb_convert_encoding runtime (#34309 leftover of #6251)
$s = $argv[1] ?? 'café';
echo mb_convert_encoding($s, 'UTF-8', 'UTF-8'), "\n";
echo bin2hex(mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8')), "\n";
$t = $argv[2] ?? 'hello';
echo mb_convert_encoding($t, 'UTF-8'), "\n";
