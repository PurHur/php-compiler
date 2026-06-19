--TEST--
Language: #[\Override] on class constants — interface/extends override compiles (#9821)
--FILE--
<?php
declare(strict_types=1);

interface I {
    public const X = 1;
}

class C implements I {
    #[\Override]
    public const X = 2;
}

class Base {
    public const Y = 10;
}

class Child extends Base {
    #[\Override]
    public const Y = 20;
}

echo C::X, "\n", Child::Y, "\n";
?>
--EXPECT--
2
20
