--TEST--
AOT: password_get_info(null) soft-null on 8.4 forward profile (#21537, reverts #20672)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$h = null;
try {
    $info = password_get_info($h);
    // Soft-null must not TypeError; unknown-algo array matches Zend (#21537).
    echo (is_array($info) && ($info['algoName'] ?? '') === 'unknown' && null === ($info['algo'] ?? false))
        ? "OK\n"
        : "BAD\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
OK
