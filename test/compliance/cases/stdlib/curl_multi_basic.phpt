--TEST--
curl_multi_init/add/exec/getcontent basic multi (#3721, ext/curl/multi.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo 'multi_init=', (int) function_exists('curl_multi_init'), "\n";
echo 'CurlMultiHandle=', (int) class_exists('CurlMultiHandle', false), "\n";
echo 'CURLM_OK=', (int) defined('CURLM_OK'), "\n";

$f1 = tempnam(sys_get_temp_dir(), 'curlm1_');
$f2 = tempnam(sys_get_temp_dir(), 'curlm2_');
file_put_contents($f1, "alpha\n");
file_put_contents($f2, "beta\n");

$mh = curl_multi_init();
$ch1 = curl_init('file://' . $f1);
$ch2 = curl_init('file://' . $f2);
curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
echo 'add1=', curl_multi_add_handle($mh, $ch1), "\n";
echo 'add2=', curl_multi_add_handle($mh, $ch2), "\n";

$running = null;
do {
    $status = curl_multi_exec($mh, $running);
} while ($running > 0 && $status === CURLM_OK);

$body1 = curl_multi_getcontent($ch1);
$body2 = curl_multi_getcontent($ch2);
echo 'body1=', is_string($body1) ? trim($body1) : 'fail', "\n";
echo 'body2=', is_string($body2) ? trim($body2) : 'fail', "\n";
echo 'status=', $status === CURLM_OK ? 'ok' : 'fail', "\n";

curl_multi_remove_handle($mh, $ch1);
curl_multi_remove_handle($mh, $ch2);
curl_multi_close($mh);
curl_close($ch1);
curl_close($ch2);
@unlink($f1);
@unlink($f2);
?>
--EXPECT--
multi_init=1
CurlMultiHandle=1
CURLM_OK=1
add1=0
add2=0
body1=alpha
body2=beta
status=ok
