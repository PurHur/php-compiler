<?php
/**
 * Issue #3721 — curl_multi_* parallel GET (file:// fixtures; network optional).
 */
declare(strict_types=1);

echo 'init=', function_exists('curl_multi_init') ? 'yes' : 'no', "\n";

$f1 = tempnam(sys_get_temp_dir(), 'curlm_repro1_');
$f2 = tempnam(sys_get_temp_dir(), 'curlm_repro2_');
file_put_contents($f1, "one\n");
file_put_contents($f2, "two\n");

$mh = curl_multi_init();
$ch1 = curl_init('file://' . $f1);
$ch2 = curl_init('file://' . $f2);
curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch1);
curl_multi_add_handle($mh, $ch2);
do {
    $status = curl_multi_exec($mh, $running);
} while ($running > 0 && $status === CURLM_OK);
$body1 = curl_multi_getcontent($ch1);
$body2 = curl_multi_getcontent($ch2);
curl_multi_remove_handle($mh, $ch1);
curl_multi_remove_handle($mh, $ch2);
curl_multi_close($mh);
curl_close($ch1);
curl_close($ch2);
@unlink($f1);
@unlink($f2);

echo (is_string($body1) && is_string($body2) && trim($body1) === 'one' && trim($body2) === 'two')
    ? "ok\n"
    : "fail\n";
