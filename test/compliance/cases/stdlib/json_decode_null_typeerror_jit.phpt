--TEST--
stdlib json_decode null operand TypeError JIT (#18601, ext/json/php_json.c)
--SKIPIF--
<?php die('skip — compiler JIT compliance via JITTest, not Zend CLI'); ?>
--FILE--
<?php
try {
    json_decode(null);
    echo "json_decode: NO_THROW\n";
} catch (TypeError $e) {
    echo 'json_decode: '.$e->getMessage()."\n";
}
?>
--EXPECT--
json_decode: json_decode(): Argument #1 ($json) must be of type string, null given
