<?php
// Repro for #18090 — readonly class property default must compile fatal (Zend/zend_compile.c).
readonly class C {
    public int $x = 1;
}
echo (new C())->x, PHP_EOL;
