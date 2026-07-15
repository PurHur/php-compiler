--TEST--
Language: foreach by-ref on object property — direct call arg after unset($v) (#18427, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

class Box {
    public array $items = [1, 2];
}

$p = new Box();
foreach ($p->items as &$x) {
    $x *= 10;
}
unset($x);

echo implode(',', $p->items), "\n";
$a = $p->items;
echo implode(',', $a), "\n";
?>
--EXPECT--
10,20
10,20
