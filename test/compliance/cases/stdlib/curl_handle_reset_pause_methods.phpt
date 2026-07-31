--TEST--
CurlHandle has no pause/reset instance methods — procedural only (#22595, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

$ch = curl_init('file:///dev/null');
echo 'reset_method=', (int) method_exists($ch, 'reset'), "\n";
echo 'pause_method=', (int) method_exists($ch, 'pause'), "\n";

try {
    $ch->pause(CURLPAUSE_ALL);
    echo "pause_call=ok\n";
} catch (Error $e) {
    echo 'pause_call=', get_class($e), "\n";
}
try {
    $ch->reset();
    echo "reset_call=ok\n";
} catch (Error $e) {
    echo 'reset_call=', get_class($e), "\n";
}

$fixture = tempnam(sys_get_temp_dir(), 'curl_oop_');
file_put_contents($fixture, "oop-body\n");
$url = 'file://' . $fixture;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$body = curl_exec($ch);
echo 'before=', is_string($body) ? trim($body) : 'fail', "\n";

curl_reset($ch);
$afterReset = curl_exec($ch);
echo 'after_reset_exec=', ($afterReset === false) ? 'false' : 'ok', "\n";

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$body2 = curl_exec($ch);
echo 'reconfigured=', is_string($body2) ? trim($body2) : 'fail', "\n";

$pauseRc = curl_pause($ch, CURLPAUSE_ALL);
echo 'pause_rc=', is_int($pauseRc) ? 'int' : 'fail', "\n";
echo 'pause_idle=', ($pauseRc === 0 || $pauseRc === 43) ? 'ok' : ('got=' . $pauseRc), "\n";

curl_close($ch);
@unlink($fixture);
?>
--EXPECT--
reset_method=0
pause_method=0
pause_call=Error
reset_call=Error
before=oop-body
after_reset_exec=false
reconfigured=oop-body
pause_rc=int
pause_idle=ok
