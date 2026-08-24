<?php
// #34524: typed :bool return with untyped % comparison — emitPropagateReturn must ret i1, not i64.
function f($x): bool
{
    return $x % 2 == 0;
}
echo f(2) ? "1\n" : "0\n";
echo f(1) ? "1\n" : "0\n";
