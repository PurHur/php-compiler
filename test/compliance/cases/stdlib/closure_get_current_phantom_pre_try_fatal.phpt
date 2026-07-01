--TEST--
Closure::getCurrent() phantom fatal before later try/catch (#14504, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);
(function (): void {
    Closure::getCurrent();
})();
try {
    echo "in try\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT_EXIT--
255
