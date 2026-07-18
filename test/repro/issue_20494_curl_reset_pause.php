<?php
declare(strict_types=1);

/**
 * Issue #20494 — curl_reset()/curl_pause() + CURLPAUSE_* (php-src-strict).
 */
echo 'reset=', function_exists('curl_reset') ? 'yes' : 'no', "\n";
echo 'pause=', function_exists('curl_pause') ? 'yes' : 'no', "\n";
echo 'CURLPAUSE_ALL=', defined('CURLPAUSE_ALL') ? (string) CURLPAUSE_ALL : 'undef', "\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_reset($ch);
$rc = curl_pause($ch, CURLPAUSE_ALL);
echo 'pause_rc_int=', is_int($rc) ? 'yes' : 'no', "\n";
echo 'pause_idle_ok=', ($rc === 0 || $rc === 43) ? 'yes' : 'no', "\n";
curl_close($ch);
echo "ok\n";
