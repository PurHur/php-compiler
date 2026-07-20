--TEST--
curl CURL_HTTP_VERSION_* + PHP 8.4 CURLOPT/CURLINFO constants (#21336, ext/curl/curl.stub.php)
--FILE--
<?php
declare(strict_types=1);

$need = [
    'CURL_HTTP_VERSION_NONE' => 0,
    'CURL_HTTP_VERSION_1_0' => 1,
    'CURL_HTTP_VERSION_1_1' => 2,
    'CURL_HTTP_VERSION_2' => 3,
    'CURL_HTTP_VERSION_2_0' => 3,
    'CURL_HTTP_VERSION_2TLS' => 4,
    'CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE' => 5,
    'CURL_HTTP_VERSION_3' => 30,
    'CURL_HTTP_VERSION_3ONLY' => 31,
    'CURLINFO_POSTTRANSFER_TIME_T' => 6291523,
    'CURLOPT_TCP_KEEPCNT' => 326,
    'CURLOPT_SERVER_RESPONSE_TIMEOUT' => 112,
    'CURLOPT_PREREQFUNCTION' => 20312,
    'CURLOPT_DEBUGFUNCTION' => 20094,
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
echo curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1) ? "setopt-http-version-ok\n" : "setopt-http-version-fail\n";
echo curl_setopt($ch, CURLOPT_SERVER_RESPONSE_TIMEOUT, 60) ? "setopt-server-timeout-ok\n" : "setopt-server-timeout-fail\n";
curl_close($ch);
?>
--EXPECT--
CURL_HTTP_VERSION_NONE=ok
CURL_HTTP_VERSION_1_0=ok
CURL_HTTP_VERSION_1_1=ok
CURL_HTTP_VERSION_2=ok
CURL_HTTP_VERSION_2_0=ok
CURL_HTTP_VERSION_2TLS=ok
CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE=ok
CURL_HTTP_VERSION_3=ok
CURL_HTTP_VERSION_3ONLY=ok
CURLINFO_POSTTRANSFER_TIME_T=ok
CURLOPT_TCP_KEEPCNT=ok
CURLOPT_SERVER_RESPONSE_TIMEOUT=ok
CURLOPT_PREREQFUNCTION=ok
CURLOPT_DEBUGFUNCTION=ok
setopt-http-version-ok
setopt-server-timeout-ok
