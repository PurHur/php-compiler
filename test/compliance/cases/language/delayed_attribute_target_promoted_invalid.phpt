--TEST--
Language: delayed attribute target — promoted parameter invalid property target (#5124)
--FILE--
<?php
#[Attribute(Attribute::TARGET_METHOD)]
class MethodOnly {}

class C {
    public function __construct(
        #[MethodOnly]
        public readonly string $x,
    ) {}
}
echo "ok\n";
--EXPECT_EXIT--
255
