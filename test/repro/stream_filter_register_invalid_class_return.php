<?php

declare(strict_types=1);

class BadFilter
{
    public function filter($in, $out, &$consumed, $closing)
    {
        return PSFS_PASS_ON;
    }
}

$name = 'bad.' . getmypid();
$ok = stream_filter_register($name, BadFilter::class);
$filters = stream_get_filters();
echo 'ok=' . var_export($ok, true) . "\n";
echo 'in_list=' . var_export(\in_array($name, $filters, true), true) . "\n";
