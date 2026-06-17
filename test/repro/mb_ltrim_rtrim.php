<?php
// UTF-8 is the compiler default; mb_internal_encoding() is not in scope for this repro.

$s = "  üñîçø∂é  ";
var_dump(mb_ltrim($s));
var_dump(mb_rtrim($s));

// mask behavior
var_dump(mb_ltrim("--héllo--", "-"));
var_dump(mb_rtrim("--héllo--", "-"));
