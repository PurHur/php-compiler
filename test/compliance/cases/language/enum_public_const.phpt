--TEST--
Language: enum public const E::X resolves at runtime (zend_enum.c, #10236 / re-#6407)
--FILE--
<?php
declare(strict_types=1);

enum E {
    case A;
    public const X = 42;
}

echo E::X, "\n";
echo constant('E::X'), "\n";
--EXPECT--
42
42
