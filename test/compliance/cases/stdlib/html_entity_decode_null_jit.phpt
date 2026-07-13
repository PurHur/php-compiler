--TEST--
stdlib html_entity_decode null operand TypeError JIT (#18590, ext/standard/html.c)
--SKIPIF--
<?php die('skip — compiler JIT compliance via JITTest, not Zend CLI'); ?>
--FILE--
<?php
try {
    html_entity_decode(null);
    echo "html_entity_decode: NO_THROW\n";
} catch (TypeError $e) {
    echo 'html_entity_decode: '.$e->getMessage()."\n";
}
?>
--EXPECT--
html_entity_decode: html_entity_decode(): Argument #1 ($string) must be of type string, null given
