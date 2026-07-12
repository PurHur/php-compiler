--TEST--
Generator valid() false after scalar return when nested in var_export (#17895, Zend/zend_generators.c)
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
--EXPECT--
valid=false
ret=99
