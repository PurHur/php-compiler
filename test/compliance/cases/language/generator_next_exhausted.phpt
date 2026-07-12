--TEST--
Generator next() on exhausted generator is silent like Zend (#17368, Zend/zend_generators.c)
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
try {
    $g->next();
    echo "next_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
valid=false
next_ok
