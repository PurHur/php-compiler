<?php
// #24105 (residual): `return` from a `catch` while a `finally` is pending SEGFAULTS under AOT.
// The straight-line shapes in j04 are fixed; this one is not. It is the case where `finally` must
// run after the return value is computed but before control leaves the function — the ordering
// Zend documents explicitly.
// FAILS AOT today by design; becomes a live guard when the residual lands.
function f(): string {
    try { throw new RuntimeException("x"); }
    catch (RuntimeException $e) { return "caught"; }
    finally { echo "fin "; }
}
echo f(), "\n";
