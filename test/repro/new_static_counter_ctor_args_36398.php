<?php
/**
 * Side-effecting sibling FuncCall args to `new` must evaluate once each (#36398).
 *
 * php-cfg hoists `mk2()` / `mk2()` before New_ with empty-usage results; after #36385
 * those producers force EXEC_RETURN when walked. ensureDeferredSibling must not
 * re-emit a producer that already has a result slot — else VM/AOT call mk a third
 * time and print `1|3` while Zend prints `1|2`.
 *
 * php-src: Zend/zend_compile.c zend_compile_new / zend_compile_func_call (ZEND_SEND_*).
 */
declare(strict_types=1);

function mk2($n)
{
    static $c = 0;

    return ++$c;
}

class Box2
{
    public $a;
    public $b;

    public function __construct($a, $b)
    {
        $this->a = $a;
        $this->b = $b;
    }
}

$o2 = new Box2(mk2(0), mk2(0));
echo $o2->a, '|', $o2->b, "\n";
