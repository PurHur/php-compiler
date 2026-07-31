--TEST--
curl_copy_handle / curl_multi_info_read / setopt / errno (#20495, ext/curl)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

foreach (['curl_copy_handle', 'curl_multi_info_read', 'curl_multi_setopt', 'curl_multi_errno'] as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}
echo 'CURLMSG_DONE=', defined('CURLMSG_DONE') ? (string) CURLMSG_DONE : 'undef', "\n";
echo 'CURLMOPT_MAXCONNECTS=', defined('CURLMOPT_MAXCONNECTS') ? (string) CURLMOPT_MAXCONNECTS : 'undef', "\n";

$fixture = tempnam(sys_get_temp_dir(), 'curl_20495_');
file_put_contents($fixture, "payload\n");
$url = 'file://' . $fixture;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$copy = curl_copy_handle($ch);
echo 'copy_class=', $copy instanceof CurlHandle ? 'CurlHandle' : 'fail', "\n";
$copyBody = curl_exec($copy);
echo 'copy_body=', is_string($copyBody) ? trim($copyBody) : 'fail', "\n";
curl_close($copy);

$mh = curl_multi_init();
echo 'errno0=', curl_multi_errno($mh), "\n";
echo 'setopt=', curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 4) ? 'true' : 'false', "\n";
try {
    curl_multi_setopt($mh, 99999, 1);
    echo "bad-option-uncaught\n";
} catch (ValueError $e) {
    echo 'bad-option-ok errno=', curl_multi_errno($mh), "\n";
}

$ch2 = curl_init($url);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch2);
$running = null;
do {
    $status = curl_multi_exec($mh, $running);
} while ($running > 0 && $status === CURLM_OK);

$queued = null;
$info = curl_multi_info_read($mh, $queued);
echo 'info_msg=', is_array($info) ? (string) $info['msg'] : 'fail', "\n";
echo 'info_result=', is_array($info) ? (string) $info['result'] : 'fail', "\n";
echo 'info_handle=', (is_array($info) && isset($info['handle']) && $info['handle'] instanceof CurlHandle) ? 'yes' : 'no', "\n";
echo 'queued_ok=', is_int($queued) ? 'yes' : 'no', "\n";

curl_multi_remove_handle($mh, $ch2);
curl_multi_close($mh);
curl_close($ch2);
curl_close($ch);
@unlink($fixture);
?>
--EXPECT--
curl_copy_handle:yes
curl_multi_info_read:yes
curl_multi_setopt:yes
curl_multi_errno:yes
CURLMSG_DONE=1
CURLMOPT_MAXCONNECTS=6
copy_class=CurlHandle
copy_body=payload
errno0=0
setopt=true
bad-option-ok errno=6
info_msg=1
info_result=0
info_handle=yes
queued_ok=yes
