<?php
// #35144 / re-#33726 — Generator::throw into try whose catch yields again must resume to that yield.
function g() {
    try {
        yield 1;
        yield 2;
    } catch (Exception $e) {
        yield 9;
    }
}
$g = g();
echo $g->current();
$g->throw(new Exception('x'));
echo $g->current();
