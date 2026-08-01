<?php
/**
 * Issue #23899 — curl upkeep / Happy Eyeballs / CAINFO constants (php-src-strict).
 *
 * CURLUPKEEP_INTERVAL_DEFAULT is not in php-src curl.stub.php (absent on Zend 8.2/8.4).
 * CURLINFO_CAINFO/CAPATH require PROFILE≥8.4 here (withheld on reference 8.2).
 */
declare(strict_types=1);

foreach ([
    'CURLUPKEEP_INTERVAL_DEFAULT',
    'CURLOPT_UPKEEP_INTERVAL_MS',
    'CURLINFO_CAINFO',
    'CURLINFO_CAPATH',
    'CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS',
] as $c) {
    echo $c, '=', defined($c) ? ('yes(' . constant($c) . ')') : 'no', "\n";
}
echo 'curl_upkeep=', function_exists('curl_upkeep') ? 'yes' : 'no', "\n";

if (defined('CURLOPT_UPKEEP_INTERVAL_MS') && function_exists('curl_init')) {
    $ch = curl_init();
    echo 'setopt_upkeep=', curl_setopt($ch, CURLOPT_UPKEEP_INTERVAL_MS, 30000) ? 'yes' : 'no', "\n";
    if (defined('CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS')) {
        echo 'setopt_happy=', curl_setopt($ch, CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS, 200) ? 'yes' : 'no', "\n";
    }
    curl_close($ch);
}
