<?php
/**
 * Repro for #22595 — CurlHandle must not expose pause/reset instance methods
 * (php-src ext/curl/curl.stub.php: final class CurlHandle {}).
 */
$ch = curl_init('https://example.com');
echo 'pause=', method_exists($ch, 'pause') ? '1' : '0', "\n";
echo 'reset=', method_exists($ch, 'reset') ? '1' : '0', "\n";
try {
    $ch->pause(CURLPAUSE_ALL);
    echo "pause_call=ok\n";
} catch (Throwable $e) {
    echo 'pause_call=', get_class($e), "\n";
}
try {
    $ch->reset();
    echo "reset_call=ok\n";
} catch (Throwable $e) {
    echo 'reset_call=', get_class($e), "\n";
}
curl_reset($ch);
echo "curl_reset=ok\n";
$rc = curl_pause($ch, CURLPAUSE_ALL);
echo 'curl_pause=', is_int($rc) ? 'int' : 'fail', "\n";
curl_close($ch);
