<?php
// Repro #34262 — AOT mb_strimwidth runtime int start/width must not SIGSEGV.
$f = 0;
$w = 3;
var_dump(mb_strimwidth('über', $f, $w, '..'));
