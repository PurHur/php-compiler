<?php
/**
 * #24065 — FILTER_FLAG_GLOBAL_RANGE bit + FILTER_THROW_ON_FAILURE phantom (ext/filter)
 *
 * php-src PHP-8.2: GLOBAL_RANGE === 0x10000000; THROW undefined.
 * PHP 8.5+: THROW === 0x10000000; GLOBAL_RANGE === 0x20000000.
 */
echo 'global_range=', FILTER_FLAG_GLOBAL_RANGE, "\n";
echo 'throw_defined=', defined('FILTER_THROW_ON_FAILURE') ? 'yes' : 'no', "\n";
if (defined('FILTER_THROW_ON_FAILURE')) {
    echo 'throw_value=', FILTER_THROW_ON_FAILURE, "\n";
}
$bucket = get_defined_constants(true)['filter'] ?? [];
echo 'bucket_global=', isset($bucket['FILTER_FLAG_GLOBAL_RANGE']) ? (string) $bucket['FILTER_FLAG_GLOBAL_RANGE'] : 'missing', "\n";
echo 'bucket_throw=', isset($bucket['FILTER_THROW_ON_FAILURE']) ? (string) $bucket['FILTER_THROW_ON_FAILURE'] : 'absent', "\n";
// Behavior: FILTER_FLAG_GLOBAL_RANGE rejects non-global (RFC 6890) IPs when set.
$r = filter_var('10.0.0.1', FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE);
echo 'priv_with_global_flag=', false === $r ? 'false' : var_export($r, true), "\n";
$r2 = filter_var('8.8.8.8', FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE);
echo 'public_with_global_flag=', false === $r2 ? 'false' : (string) $r2, "\n";
