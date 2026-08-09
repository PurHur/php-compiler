<?php
// AOT compile-only repro #29354 — must not LogicException at lower (runtime wordwrap(width) SEGV is pre-existing)
error_reporting(E_ALL & ~E_DEPRECATED);
$r = wordwrap('abcd', 2, "\n", null);
echo ($r === 'abcd') ? "ok\n" : "bad\n";
