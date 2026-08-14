--TEST--
language: Generator current/valid/key/next/send excess argc → ArgumentCountError JIT (#30907, zend_generators.c)
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
show('current', fn() => $g->current(1));
show('valid', fn() => $g->valid(1));
show('key', fn() => $g->key(1));
show('next', function () use ($g) { $g->next(1); return 'ok'; });
show('send', function () {
    $h = (function () { yield 1; })();
    $h->current();
    return $h->send(1, 'x');
});
show('send_ok', function () {
    $h = (function () { yield 1; yield 42; })();
    $h->current();
    return $h->send('x');
});
show('current_ok', fn() => (function () { yield 7; })()->current());
--EXPECT--
current: ArgumentCountError: Generator::current() expects exactly 0 arguments, 1 given
valid: ArgumentCountError: Generator::valid() expects exactly 0 arguments, 1 given
key: ArgumentCountError: Generator::key() expects exactly 0 arguments, 1 given
next: ArgumentCountError: Generator::next() expects exactly 0 arguments, 1 given
send: ArgumentCountError: Generator::send() expects exactly 1 argument, 2 given
send_ok: 42
current_ok: 7
