<?php

/**
 * #23949 AOT negative probe — 3-arg parse_str must ArgumentCountError (non-zero exit).
 * No try/catch: AOT try/catch around this builtin still module-verifies red on some hosts.
 */

$out = [];
parse_str('a=1;b=2', $out, ';');
echo "FAIL: 3-arg accepted\n";
