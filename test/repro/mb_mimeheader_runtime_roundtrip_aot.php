<?php
// Non-foldable encode→decode UTF-8 roundtrip (#34310 leftover of #34307).
// Array concat keeps compileTimeString unset so NestedJIT must run (literal 'Hello 世界' folds).
$parts = ['Hello ', '世界'];
$s = $parts[0].$parts[1];
$e = mb_encode_mimeheader($s);
echo $e, "\n";
echo mb_decode_mimeheader($e), "\n";
