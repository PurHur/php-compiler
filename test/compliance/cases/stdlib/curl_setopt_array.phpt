--TEST--
curl_setopt_array() bulk CURLOPT setup (#6695, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

$ch = curl_init();
echo curl_setopt_array($ch, [CURLOPT_URL => 'http://example.test/', CURLOPT_RETURNTRANSFER => true]) ? "ok\n" : "fail\n";

try {
    curl_setopt_array($ch, 'not-array');
    echo "type-fail\n";
} catch (TypeError $e) {
    echo "type-ok\n";
}

try {
    curl_setopt_array($ch, ['not-an-option' => 1]);
    echo "invalid-key-fail\n";
} catch (ValueError $e) {
    echo "invalid-key-ok\n";
}

try {
    curl_setopt_array($ch, [999999 => 1]);
    echo "invalid-int-fail\n";
} catch (ValueError $e) {
    echo "invalid-int-ok\n";
}

enum E: string { case A = 'x'; }
try {
    curl_setopt_array($ch, [CURLOPT_URL => E::A]);
    echo "enum-value-fail\n";
} catch (TypeError $e) {
    echo "enum-value-ok\n";
}
?>
--EXPECT--
ok
type-ok
invalid-key-ok
invalid-int-ok
enum-value-ok
