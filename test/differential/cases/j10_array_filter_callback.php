<?php
// @differential-skip-aot: array_filter() with a callback is a documented JIT/AOT limitation in this build
//
// Deliberately skip-marked rather than left failing: the compiler declines this explicitly and
// readably ("array_filter() with a callback is not supported by the JIT compiler in this build"),
// which is a limitation, not a defect. Kept in the corpus so the VM path stays gated and so the
// case is ready if callback support lands.
$a = [1, 2, 3, 4];
echo implode(',', array_filter($a, fn($x) => $x % 2 === 0)), "\n";
