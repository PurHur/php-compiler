--TEST--
Language: instance method first-class callable (expr)->m(...) (#10168, #9185, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class MC {
    public function m(): int { return 7; }
}

$c = (new MC())->m(...);
var_export($c instanceof Closure);
echo "\n";
echo $c(), "\n";

$obj = new MC();
$f = $obj->m(...);
echo $f(), "\n";

--EXPECT--
true
7
7
