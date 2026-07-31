--TEST--
curl_upkeep() easy-handle connection upkeep (#20977, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', (int) function_exists('curl_upkeep'), "\n";

$ch = curl_init();
$ok = curl_upkeep($ch);
echo 'idle_type=', gettype($ok), "\n";
echo 'idle_ok=', $ok ? '1' : '0', "\n";
echo 'errno=', (string) curl_errno($ch), "\n";

try {
    curl_upkeep('x');
    echo "bad=ok\n";
} catch (TypeError $e) {
    echo "bad=type\n";
}
try {
    curl_upkeep();
    echo "argc=ok\n";
} catch (ArgumentCountError $e) {
    echo "argc=err\n";
}

curl_close($ch);
?>
--EXPECT--
exists=1
idle_type=boolean
idle_ok=1
errno=0
bad=type
argc=err
