<?php
declare(strict_types=1);

/**
 * #25814 — curl_error() must expose libcurl CURLOPT_ERRORBUFFER text
 * (host/port/timing prefix), not only curl_easy_strerror().
 */
$ch = curl_init('http://127.0.0.1:1/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 1,
    CURLOPT_TIMEOUT => 1,
]);
curl_exec($ch);
$errno = curl_errno($ch);
$err = curl_error($ch);
echo 'errno=', $errno, "\n";
echo (preg_match('/^Failed to connect to 127\.0\.0\.1 port 1 after \d+ ms: /', $err) === 1)
    ? "shape=ok\n"
    : ('shape=bad:' . $err . "\n");

$ok = curl_init('file://' . __FILE__);
curl_setopt($ok, CURLOPT_RETURNTRANSFER, true);
curl_exec($ok);
echo 'ok_errno=', curl_errno($ok), "\n";
echo 'ok_empty=', curl_error($ok) === '' ? "yes\n" : ("no:" . curl_error($ok) . "\n");
curl_close($ok);
curl_close($ch);
