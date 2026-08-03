<?php
/**
 * Issue #27219 — AOT Closure::bindTo() with bound $this + private scope must match Zend/VM/JIT.
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_closures.c
 */
class A
{
    private $x = 7;
}
$f = function () {
    return $this->x;
};
$b = $f->bindTo(new A(), 'A');
echo $b(), "\n";
