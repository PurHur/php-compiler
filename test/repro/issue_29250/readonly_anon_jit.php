<?php
// #29250 — JIT must Error (not crash/verify-fail) on readonly anonymous promoted write.
$o = new readonly class {
    public function __construct(public int $x = 1) {}
};
$o->x = 2;
echo "ok\n";
