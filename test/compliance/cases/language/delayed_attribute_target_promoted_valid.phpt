--TEST--
Language: delayed attribute target — promoted parameter valid property target (#5124)
--FILE--
<?php
#[Attribute(Attribute::TARGET_PROPERTY)]
class PropOnly {}

class C {
    public function __construct(
        #[PropOnly]
        public readonly string $x,
    ) {}
}
echo (new C('hi'))->x, "\n";
--EXPECT--
hi
