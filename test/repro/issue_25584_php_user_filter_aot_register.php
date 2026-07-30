<?php

declare(strict_types=1);

// Minimal AOT probe for #25584 — subclass + stream_filter_register (no bucket/LLVM path).
class UF extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        return PSFS_PASS_ON;
    }
}

echo class_exists('php_user_filter') ? "class_ok\n" : "class_fail\n";
echo stream_filter_register('t', UF::class) ? "reg_ok\n" : "reg_fail\n";
