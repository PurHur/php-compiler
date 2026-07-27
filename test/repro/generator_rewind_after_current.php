<?php
function g() {
    yield 1;
    yield 2;
}

$g = g();
echo 'cur=', var_export($g->current(), true), "\n";
$g->rewind();
echo "rewind_ok\n";
echo 'cur2=', var_export($g->current(), true), "\n";

$g2 = g();
$g2->key();
$g2->rewind();
echo "rewind_after_key_ok\n";

$g3 = g();
$g3->valid();
$g3->rewind();
echo "rewind_after_valid_ok\n";

$g4 = g();
$g4->current();
$g4->next();
try {
    $g4->rewind();
    echo "rewind_after_next_ok\n";
} catch (Throwable $e) {
    echo 'rewind_after_next_err=', get_class($e), ':', $e->getMessage(), "\n";
}

$g5 = g();
$g5->rewind();
$g5->rewind();
echo "rewind_twice_ok\n";
