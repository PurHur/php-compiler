<?php
/** Maintainer repro for #10590 — extract() must not leave imported vars as undefined. */
$arr = ['a' => 1, 'b' => 2];
extract($arr, EXTR_SKIP);
$ok = ($a === 1 && $b === 2);
echo $ok ? "OK\n" : "FAIL\n";
