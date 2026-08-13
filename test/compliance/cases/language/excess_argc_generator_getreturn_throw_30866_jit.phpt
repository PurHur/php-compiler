--TEST--
language: Generator getReturn/throw excess argc → ArgumentCountError JIT (#30866, zend_generators.c)
--FILE--
<?php
function gen_argc_return_jit() { yield 1; return 2; }
$g = gen_argc_return_jit();
$g->next();
try {
    var_export($g->getReturn(1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
function gen_argc_throw_jit() { try { yield 1; } catch (Throwable $e) { echo "caught\n"; } }
$h = gen_argc_throw_jit();
$h->current();
try {
    $h->throw(new Exception('x'), 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$h2 = gen_argc_throw_jit();
$h2->current();
try {
    $h2->throw();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$g2 = gen_argc_return_jit();
$g2->next();
echo 'ok=', var_export($g2->getReturn(), true);
$h3 = gen_argc_throw_jit();
$h3->current();
$h3->throw(new Exception('x'));
echo "\n";
--EXPECT--
ArgumentCountError: Generator::getReturn() expects exactly 0 arguments, 1 given
ArgumentCountError: Generator::throw() expects exactly 1 argument, 2 given
ArgumentCountError: Generator::throw() expects exactly 1 argument, 0 given
ok=2caught
