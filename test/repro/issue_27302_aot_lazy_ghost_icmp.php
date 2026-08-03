<?php

/**
 * Repro #27302 — AOT module verify icmp i8 vs i32 on lazy ghost init.
 *
 * PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_27302_aot_lazy_ghost_icmp.php
 * PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/lg.bin test/repro/issue_27302_aot_lazy_ghost_icmp.php && /tmp/lg.bin
 */
class Lg
{
    public int $x;

    public function __construct()
    {
        $this->x = 7;
    }
}

$o = (new ReflectionClass(Lg::class))->newLazyGhost(function (object $obj) {
    $obj->__construct();
});
echo $o->x, "\n";
