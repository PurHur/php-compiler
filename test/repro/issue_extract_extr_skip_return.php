<?php
// Issue #11509 — extract(EXTR_SKIP) return count must not include skipped keys.
$a = 1;
$n = extract(['a' => 99, 'b' => 2], EXTR_SKIP);
echo "n={$n}\n";
echo "a={$a}\n";
echo "b={$b}\n";
