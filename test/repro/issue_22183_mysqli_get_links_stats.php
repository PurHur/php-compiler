<?php
/**
 * Repro #22183 — mysqli_get_links_stats() registration + php-src keys.
 */
echo 'mysqli_get_links_stats=', function_exists('mysqli_get_links_stats') ? 'yes' : 'NO', "\n";
if (!function_exists('mysqli_get_links_stats')) {
    exit(1);
}
$s = mysqli_get_links_stats();
echo 'keys=', implode(',', array_keys($s)), "\n";
echo 'total=', (int) $s['total'], ' active=', (int) $s['active_plinks'], ' cached=', (int) $s['cached_plinks'], "\n";
