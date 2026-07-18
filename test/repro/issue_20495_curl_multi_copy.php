<?php
/**
 * Issue #20495 — curl_copy_handle + curl_multi_info_read/setopt/errno (php-src-strict).
 */
declare(strict_types=1);

$failed = 0;
foreach (['curl_copy_handle', 'curl_multi_info_read', 'curl_multi_setopt', 'curl_multi_errno'] as $fn) {
    if (!function_exists($fn)) {
        echo "$fn: missing\n";
        ++$failed;
    }
}

$fixture = tempnam(sys_get_temp_dir(), 'curl_20495_');
file_put_contents($fixture, "copied\n");
$url = 'file://' . $fixture;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$copy = curl_copy_handle($ch);
if (!($copy instanceof CurlHandle)) {
    echo "copy_handle type fail\n";
    ++$failed;
} else {
    $body = curl_exec($copy);
    if (!is_string($body) || trim($body) !== 'copied') {
        echo "copy_handle body fail\n";
        ++$failed;
    }
    curl_close($copy);
}

$mh = curl_multi_init();
if (0 !== curl_multi_errno($mh)) {
    echo "fresh errno expected 0\n";
    ++$failed;
}
$setoptOk = curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 8);
if (true !== $setoptOk) {
    echo "setopt MAXCONNECTS fail\n";
    ++$failed;
}
try {
    curl_multi_setopt($mh, 99999, 1);
    echo "bad option uncaught\n";
    ++$failed;
} catch (ValueError $e) {
    if (6 !== curl_multi_errno($mh)) {
        echo "errno after bad option expected 6, got ", curl_multi_errno($mh), "\n";
        ++$failed;
    }
}

$ch2 = curl_init($url);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch2);
do {
    $status = curl_multi_exec($mh, $running);
} while ($running > 0 && $status === CURLM_OK);

$queued = null;
$info = curl_multi_info_read($mh, $queued);
if (!is_array($info) || ($info['msg'] ?? null) !== CURLMSG_DONE || ($info['result'] ?? null) !== 0) {
    echo "info_read fail: ", var_export($info, true), "\n";
    ++$failed;
} elseif (!isset($info['handle']) || !($info['handle'] instanceof CurlHandle)) {
    echo "info_read handle missing\n";
    ++$failed;
}
if (!is_int($queued) || $queued < 0) {
    echo "queued messages bad: ", var_export($queued, true), "\n";
    ++$failed;
}

curl_multi_remove_handle($mh, $ch2);
curl_multi_close($mh);
curl_close($ch2);
curl_close($ch);
@unlink($fixture);

echo $failed > 0 ? "FAIL\n" : "ok\n";
exit($failed > 0 ? 1 : 0);
