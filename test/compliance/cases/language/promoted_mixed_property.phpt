--TEST--
Constructor promoted and declared mixed properties accept any value (#12348)
--FILE--
<?php
class PromotedBox {
    public function __construct(
        public mixed $value,
    ) {
    }
}

class DeclaredBox {
    public mixed $value;
}

$p = new PromotedBox(42);
$d = new DeclaredBox();
$d->value = 'ok';

echo $p->value . "\n";
echo $d->value . "\n";
?>
--EXPECT--
42
ok
