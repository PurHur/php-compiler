<?php
#[Attribute(Attribute::TARGET_PROPERTY)]
class PropOnly {}

class C {
    public function __construct(
        #[PropOnly]
        public readonly string $x,
    ) {}
}
echo "ok\n";
