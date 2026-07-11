<?php

declare(strict_types=1);

echo class_exists('php_user_filter') ? "true\n" : "false\n";
echo defined('PSFS_PASS_ON') ? 'psfs='.PSFS_PASS_ON."\n" : "psfs=missing\n";

class UpperFilter extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        return PSFS_PASS_ON;
    }
}

echo stream_filter_register('upper.test', UpperFilter::class) ? "registered\n" : "register_fail\n";
echo "ok\n";
