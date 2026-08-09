<?php
// AOT compile-only (#29354): null cut_long_words must lower via Z_PARAM_BOOL, not LogicException.
// Runtime execute of wordwrap(width) still SEGV on master (WordwrapBuiltinTest); this guards compile.
error_reporting(E_ALL & ~E_DEPRECATED);
$r = wordwrap('abcd', 2, "\n", null);
echo ($r === 'abcd') ? "ok\n" : "bad\n";
