--TEST--
Language: #[\Override] on properties — parent override compiles (#9822)
--FILE--
<?php
declare(strict_types=1);

class Base {
    public int $x = 1;
}

class Child extends Base {
    #[\Override]
    public int $x = 2;
}

echo (new Child())->x, "\n";
?>
--EXPECT--
2
