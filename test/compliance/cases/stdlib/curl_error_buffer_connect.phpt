--TEST--
curl_error() uses CURLOPT_ERRORBUFFER connect prefix (#25814, ext/curl/interface.c)
--FILE--
<?php
declare(strict_types=1);

$ch = curl_init('http://127.0.0.1:1/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 1,
    CURLOPT_TIMEOUT => 1,
]);
curl_exec($ch);
echo 'errno=', curl_errno($ch), "\n";
$err = curl_error($ch);
echo (preg_match('/^Failed to connect to 127\.0\.0\.1 port 1 after \d+ ms: /', $err) === 1)
    ? "shape=ok\n"
    : ('shape=bad:' . $err . "\n");
echo 'defined_errbuf=', (int) defined('CURLOPT_ERRORBUFFER'), "\n";
curl_close($ch);

$fixture = tempnam(sys_get_temp_dir(), 'curl_err_ok_');
file_put_contents($fixture, "ok\n");
$ch2 = curl_init('file://' . $fixture);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch2);
echo 'ok_errno=', curl_errno($ch2), "\n";
echo 'ok_empty=', curl_error($ch2) === '' ? "yes\n" : ("no\n");
curl_close($ch2);
@unlink($fixture);
?>
--EXPECT--
errno=7
shape=ok
defined_errbuf=1
ok_errno=0
ok_empty=yes
