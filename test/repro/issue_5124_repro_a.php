<?php
#[Attribute(Attribute::TARGET_METHOD)]
class MethodOnly {}

class C {
    public function __construct(
        #[MethodOnly]
        public readonly string $x,
    ) {}
}
