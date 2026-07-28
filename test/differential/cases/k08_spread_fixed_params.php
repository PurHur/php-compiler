<?php
// Spread into fixed UNTYPED params — passes AOT (10/10 at --repeat 10); this locks the coverage in.
//
// Kept deliberately: I first reported this shape as silent wrong output (0 instead of 6) in #24167
// and had to retract it — that measurement came from a probe running concurrently with a second
// sweep container against the same bind-mounted repo, sharing the helper-runtime build cache. The
// case earns its place by pinning the shape that a contaminated run made look broken.
//
// The related shapes that ARE broken have their own cases and issue: see k09 (variadic pack is not
// a usable array) and #24167 (spread into fixed TYPED params fails to compile with
// "Unsupported cast for arg type int64 from __hashtable__*").
function s3($a, $b, $c) { return $a + $b + $c; }
$p = [1, 2, 3];
echo s3(...$p), "\n";
