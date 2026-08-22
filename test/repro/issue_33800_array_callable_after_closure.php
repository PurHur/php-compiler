<?php
/**
 * #33800 — AOT: ['Class','method']() after a closure definition aborts (rc=134).
 *
 * Once a closure body is compiled, VmClosure::resolveIndirectCall treated any
 * TYPE_VALUE callee as RuntimeIndirectClosureCall before array-callable fold.
 * php-src: Zend/zend_execute.c ZEND_INIT_DYNAMIC_CALL.
 */
class SC
{
    public static $v = 12;
}

$f = static function (): int {
    return SC::$v;
};

class AC
{
    public static function m(): int
    {
        return 42;
    }
}

$cb = [AC::class, 'm'];
echo $f(), "\n";
echo $cb(), "\n";
