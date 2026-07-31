<?php
// Issue #25926 — filter_input(..., null) → false like filter_var (re-#18943).
$r = @filter_var('x', null);
echo $r === false ? "false\n" : "bad\n";
$_GET['q'] = 'x';
$r2 = @filter_input(INPUT_GET, 'q', null);
echo $r2 === false ? "false\n" : "bad\n";
