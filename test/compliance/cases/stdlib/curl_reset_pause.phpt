--TEST--
curl_reset() / curl_pause() easy-handle lifecycle (#20494, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo 'reset_exists=', (int) function_exists('curl_reset'), "\n";
echo 'pause_exists=', (int) function_exists('curl_pause'), "\n";
echo 'pause_all=', defined('CURLPAUSE_ALL') ? (string) CURLPAUSE_ALL : 'undef', "\n";
echo 'pause_cont=', defined('CURLPAUSE_CONT') ? (string) CURLPAUSE_CONT : 'undef', "\n";

$fixture = tempnam(sys_get_temp_dir(), 'curl_reset_');
file_put_contents($fixture, "before-reset\n");
$url = 'file://' . $fixture;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$body = curl_exec($ch);
echo 'before=', is_string($body) ? trim($body) : 'fail', "\n";

curl_reset($ch);
$afterReset = curl_exec($ch);
echo 'after_reset_exec=', ($afterReset === false) ? 'false' : 'ok', "\n";
echo 'after_reset_errno=', curl_errno($ch) > 0 ? 'nonzero' : 'zero', "\n";

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$body2 = curl_exec($ch);
echo 'reconfigured=', is_string($body2) ? trim($body2) : 'fail', "\n";

$pauseRc = curl_pause($ch, CURLPAUSE_ALL);
echo 'pause_rc=', is_int($pauseRc) ? 'int' : 'fail', "\n";
// Idle easy handle: libcurl returns CURLE_BAD_FUNCTION_ARGUMENT (43) when !conn
// (curl/lib/easy.c curl_easy_pause); mid-transfer returns CURLE_OK (0).
echo 'pause_idle=', ($pauseRc === 0 || $pauseRc === 43) ? 'ok' : ('got=' . $pauseRc), "\n";
$contRc = curl_pause($ch, CURLPAUSE_CONT);
echo 'cont_rc=', is_int($contRc) ? 'int' : 'fail', "\n";
echo 'cont_idle=', ($contRc === 0 || $contRc === 43) ? 'ok' : ('got=' . $contRc), "\n";

try {
    curl_reset('x');
    echo "reset_bad=ok\n";
} catch (TypeError $e) {
    echo "reset_bad=type\n";
}
try {
    curl_pause($ch);
    echo "pause_argc=ok\n";
} catch (ArgumentCountError $e) {
    echo "pause_argc=err\n";
}

curl_close($ch);
@unlink($fixture);
?>
--EXPECT--
reset_exists=1
pause_exists=1
pause_all=5
pause_cont=0
before=before-reset
after_reset_exec=false
after_reset_errno=nonzero
reconfigured=before-reset
pause_rc=int
pause_idle=ok
cont_rc=int
cont_idle=ok
reset_bad=type
pause_argc=err
