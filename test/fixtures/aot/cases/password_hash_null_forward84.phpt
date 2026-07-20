--TEST--
AOT: password_hash(null) soft-null on 8.4 forward profile (#21210)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$p = null;
try {
    $h = password_hash($p, PASSWORD_DEFAULT);
    // Soft-null must not TypeError; hash may be string (VM/JIT) or false on
    // some AOT helper builds — either is fine vs TypeError (#21210).
    echo (false === $h || is_string($h)) ? "OK\n" : "BAD\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
OK
