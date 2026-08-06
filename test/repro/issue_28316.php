<?php
/**
 * #28316 — URL/Base64 excess argc → ArgumentCountError (Zend), not LogicException.
 */
$cases = [
    'base64_encode' => static function () { base64_encode('a', 'x'); },
    'base64_decode' => static function () { base64_decode('YQ==', true, 'x'); },
    'urlencode' => static function () { urlencode('a', 'x'); },
    'urldecode' => static function () { urldecode('a', 'x'); },
    'rawurlencode' => static function () { rawurlencode('a', 'x'); },
    'rawurldecode' => static function () { rawurldecode('a', 'x'); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, ":OK\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo 'ok:', base64_encode('a'), ':', urlencode('a'), "\n";
