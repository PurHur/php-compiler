--TEST--
Language: Closure::bindTo(inline new $object, null) — bound invoke reads $this property (#15900, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);
class C {
    public int $x = 1;
}
echo (function (): int {
    return $this->x;
})->bindTo(new C(), null)(), "\n";
$o = new C();
echo (function (): int {
    return $this->x;
})->bindTo($o, null)(), "\n";
?>
--EXPECT--
1
1
