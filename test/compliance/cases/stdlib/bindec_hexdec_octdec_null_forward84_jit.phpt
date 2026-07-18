--TEST--
stdlib bindec()/hexdec()/octdec() null TypeError on 8.4 forward — JIT (#20658)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
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
bindec(): Argument #1 ($binary_string) must be of type string, null given
hexdec(): Argument #1 ($hex_string) must be of type string, null given
octdec(): Argument #1 ($octal_string) must be of type string, null given
10,255,15
