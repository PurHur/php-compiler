--TEST--
Language: #[\Override] on properties — parent override compiles under PROFILE=8.5 (#9822, #25138)
--ENV--
PHP_COMPILER_PROFILE=8.5
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
