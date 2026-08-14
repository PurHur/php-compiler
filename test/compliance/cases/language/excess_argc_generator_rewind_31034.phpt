--TEST--
language: Generator::rewind() excess argc → ArgumentCountError (#31034, zend_generators.c)
--FILE--
<?php
function show($label, $fn) {
    try {
        $r = $fn();
        echo $label, ': ', is_bool($r) ? ($r ? 'true' : 'false') : var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
function gen() { yield 1; return 2; }
$g = gen();
show('rewind', function () use ($g) { $g->rewind(1); return 'ok'; });
show('rewind_ok', function () {
    $h = (function () { yield 7; })();
    $h->rewind();
    return $h->current();
});
--EXPECT--
rewind: ArgumentCountError: Generator::rewind() expects exactly 0 arguments, 1 given
rewind_ok: 7
