--TEST--
curl PHP 8.4 CURLOPT/CURLINFO constants on PROFILE=8.4 (#22837, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$need = [
    'CURL_HTTP_VERSION_3' => 30,
    'CURL_HTTP_VERSION_3ONLY' => 31,
    'CURLINFO_POSTTRANSFER_TIME_T' => 6291523,
    'CURLOPT_TCP_KEEPCNT' => 326,
    'CURLOPT_SERVER_RESPONSE_TIMEOUT' => 112,
    'CURLOPT_PREREQFUNCTION' => 20312,
    'CURLOPT_DEBUGFUNCTION' => 20094,
    // Still present on 8.4 (FTP_RESPONSE_TIMEOUT remains; SERVER is alias).
    'CURLOPT_FTP_RESPONSE_TIMEOUT' => 112,
    'CURLOPT_MAXFILESIZE' => 114,
];
foreach ($need as $name => $want) {
    if (!defined($name)) {
        echo $name, "=UNDEF\n";
        continue;
    }
    $got = constant($name);
    echo $name, '=', $got === $want ? 'ok' : ("bad:{$got}"), "\n";
}

$ch = curl_init();
echo curl_setopt($ch, CURLOPT_SERVER_RESPONSE_TIMEOUT, 60) ? "setopt-server-timeout-ok\n" : "setopt-server-timeout-fail\n";
curl_close($ch);
?>
--EXPECT--
CURL_HTTP_VERSION_3=ok
CURL_HTTP_VERSION_3ONLY=ok
CURLINFO_POSTTRANSFER_TIME_T=ok
CURLOPT_TCP_KEEPCNT=ok
CURLOPT_SERVER_RESPONSE_TIMEOUT=ok
CURLOPT_PREREQFUNCTION=ok
CURLOPT_DEBUGFUNCTION=ok
CURLOPT_FTP_RESPONSE_TIMEOUT=ok
CURLOPT_MAXFILESIZE=ok
setopt-server-timeout-ok
