--TEST--
curl CURLOPT_* / CURLINFO_* constants for common HTTP clients (#21137)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

$need = [
    'CURLOPT_TIMEOUT' => 13,
    'CURLOPT_CONNECTTIMEOUT' => 78,
    'CURLOPT_FOLLOWLOCATION' => 52,
    'CURLOPT_POSTFIELDS' => 10015,
    'CURLOPT_USERAGENT' => 10018,
    'CURLOPT_SSL_VERIFYPEER' => 64,
    'CURLOPT_COOKIE' => 10022,
    'CURLOPT_PROXY' => 10004,
    'CURLINFO_RESPONSE_CODE' => 2097154,
    'CURLINFO_TOTAL_TIME' => 3145731,
];
// Bare names (not constant()) so AOT matches VM (#21137).
$got = [
    'CURLOPT_TIMEOUT' => defined('CURLOPT_TIMEOUT') ? CURLOPT_TIMEOUT : null,
    'CURLOPT_CONNECTTIMEOUT' => defined('CURLOPT_CONNECTTIMEOUT') ? CURLOPT_CONNECTTIMEOUT : null,
    'CURLOPT_FOLLOWLOCATION' => defined('CURLOPT_FOLLOWLOCATION') ? CURLOPT_FOLLOWLOCATION : null,
    'CURLOPT_POSTFIELDS' => defined('CURLOPT_POSTFIELDS') ? CURLOPT_POSTFIELDS : null,
    'CURLOPT_USERAGENT' => defined('CURLOPT_USERAGENT') ? CURLOPT_USERAGENT : null,
    'CURLOPT_SSL_VERIFYPEER' => defined('CURLOPT_SSL_VERIFYPEER') ? CURLOPT_SSL_VERIFYPEER : null,
    'CURLOPT_COOKIE' => defined('CURLOPT_COOKIE') ? CURLOPT_COOKIE : null,
    'CURLOPT_PROXY' => defined('CURLOPT_PROXY') ? CURLOPT_PROXY : null,
    'CURLINFO_RESPONSE_CODE' => defined('CURLINFO_RESPONSE_CODE') ? CURLINFO_RESPONSE_CODE : null,
    'CURLINFO_TOTAL_TIME' => defined('CURLINFO_TOTAL_TIME') ? CURLINFO_TOTAL_TIME : null,
];
foreach ($need as $name => $want) {
    if (null === $got[$name]) {
        echo $name, "=UNDEF\n";
        continue;
    }
    echo $name, '=', $got[$name] === $want ? 'ok' : ("bad:{$got[$name]}"), "\n";
}

$ch = curl_init();
echo curl_setopt($ch, CURLOPT_TIMEOUT, 5) ? "setopt-timeout-ok\n" : "setopt-timeout-fail\n";
echo curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true) ? "setopt-follow-ok\n" : "setopt-follow-fail\n";
echo curl_setopt($ch, CURLOPT_USERAGENT, 'phpc-test') ? "setopt-ua-ok\n" : "setopt-ua-fail\n";
echo curl_setopt_array($ch, [
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_SSL_VERIFYPEER => false,
]) ? "setopt-array-ok\n" : "setopt-array-fail\n";
curl_close($ch);
?>
--EXPECT--
CURLOPT_TIMEOUT=ok
CURLOPT_CONNECTTIMEOUT=ok
CURLOPT_FOLLOWLOCATION=ok
CURLOPT_POSTFIELDS=ok
CURLOPT_USERAGENT=ok
CURLOPT_SSL_VERIFYPEER=ok
CURLOPT_COOKIE=ok
CURLOPT_PROXY=ok
CURLINFO_RESPONSE_CODE=ok
CURLINFO_TOTAL_TIME=ok
setopt-timeout-ok
setopt-follow-ok
setopt-ua-ok
setopt-array-ok
