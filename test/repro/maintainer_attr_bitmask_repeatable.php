<?php
#[Attribute(Attribute::TARGET_ALL | Attribute::IS_REPEATABLE)]
class Rep {
    public function __construct(public int $n = 0) {}
}

echo "ok\n";
