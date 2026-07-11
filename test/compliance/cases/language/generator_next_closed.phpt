--TEST--
Generator::next() on closed generator is no-op; foreach throws traverse error (#17736, Zend/zend_generators.c)
--FILE--
<?php
declare(strict_types=1);

$g = (function (): Generator {
    yield 1;

    return 99;
})();
$g->next();
$g->next();
echo 'valid=', var_export($g->valid(), true), "\n";
echo 'ret=', $g->getReturn(), "\n";
try {
    $g->next();
    echo "next_ok\n";
} catch (Throwable $e) {
    echo 'next_fail: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    foreach ($g as $v) {
        echo $v;
    }
    echo "foreach_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
valid=false
ret=99
next_ok
Exception: Cannot traverse an already closed generator
