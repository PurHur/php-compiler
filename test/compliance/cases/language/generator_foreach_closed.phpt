--TEST--
Generator foreach on closed generator throws traverse error (#17368, Zend/zend_generators.c)
--FILE--
<?php
declare(strict_types=1);

$g = (function (): Generator {
    yield 1;

    return 99;
})();
$g->next();
$g->next();
try {
    foreach ($g as $v) {
        echo $v;
    }
    echo "foreach_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Exception: Cannot traverse an already closed generator
