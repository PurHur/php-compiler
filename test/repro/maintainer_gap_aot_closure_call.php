<?php
/**
 * Issue #26872 — AOT Closure::call() with temporary $this must match Zend/VM/JIT.
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_closures.c
 */
class A
{
    private $x = 5;
}
$f = function () {
    return $this->x;
};
echo $f->call(new A()), "\n";
