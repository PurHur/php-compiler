--TEST--
curl upkeep / Happy Eyeballs / CAINFO constants (#23899, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

// Base 8.2 surface — CURLOPT_UPKEEP_INTERVAL_MS + CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS.
$base = [
    'CURLOPT_UPKEEP_INTERVAL_MS' => 281,
    'CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS' => 271,
];
foreach ($base as $name => $want) {
    if (!defined($name)) {
        echo $name, "=UNDEF\n";
        continue;
    }
    $got = constant($name);
    echo $name, '=', $got === $want ? 'ok' : ("bad:{$got}"), "\n";
}

// CURLUPKEEP_INTERVAL_DEFAULT is not in php-src curl.stub.php (Zend 8.2/8.4).
echo 'CURLUPKEEP_INTERVAL_DEFAULT=', defined('CURLUPKEEP_INTERVAL_DEFAULT') ? 'phantom' : 'absent', "\n";

$ch = curl_init();
echo curl_setopt($ch, CURLOPT_UPKEEP_INTERVAL_MS, 30000) ? "setopt-upkeep-ok\n" : "setopt-upkeep-fail\n";
echo curl_setopt($ch, CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS, 200) ? "setopt-happy-ok\n" : "setopt-happy-fail\n";
curl_close($ch);
echo 'curl_upkeep=', function_exists('curl_upkeep') ? 'yes' : 'no', "\n";
?>
--EXPECT--
CURLOPT_UPKEEP_INTERVAL_MS=ok
CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS=ok
CURLUPKEEP_INTERVAL_DEFAULT=absent
setopt-upkeep-ok
setopt-happy-ok
curl_upkeep=yes
