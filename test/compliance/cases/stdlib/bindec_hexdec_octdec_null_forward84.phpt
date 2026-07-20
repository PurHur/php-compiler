--TEST--
stdlib bindec()/hexdec()/octdec() null soft-null on 8.4 forward (#21244, reverts #20658)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (): bool { return true; });
foreach (['bindec' => 'binary_string', 'hexdec' => 'hex_string', 'octdec' => 'octal_string'] as $f => $param) {
    try {
        $r = $f(null);
        echo $f, ' uncaught ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo bindec('1010'), ',', hexdec('ff'), ',', octdec('17'), "\n";
?>
--EXPECT--
bindec uncaught 0
hexdec uncaught 0
octdec uncaught 0
10,255,15
