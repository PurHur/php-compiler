--TEST--
curl CURLINFO_CAINFO/CAPATH on PROFILE=8.4 (#23899, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$need = [
    'CURLINFO_CAINFO' => 1048637,
    'CURLINFO_CAPATH' => 1048638,
    'CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS' => 271,
    'CURLOPT_UPKEEP_INTERVAL_MS' => 281,
];
foreach ($need as $name => $want) {
    if (!defined($name)) {
        echo $name, "=UNDEF\n";
        continue;
    }
    $got = constant($name);
    echo $name, '=', $got === $want ? 'ok' : ("bad:{$got}"), "\n";
}
?>
--EXPECT--
CURLINFO_CAINFO=ok
CURLINFO_CAPATH=ok
CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS=ok
CURLOPT_UPKEEP_INTERVAL_MS=ok
