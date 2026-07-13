--TEST--
stdlib html_entity_decode null operand TypeError (#18590, ext/standard/html.c)
--SKIPIF--
<?php die('skip — compiler VM compliance via VMTest, not Zend CLI'); ?>
--FILE--
<?php
foreach (['html_entity_decode', 'htmlspecialchars_decode'] as $fn) {
    try {
        $fn(null);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
html_entity_decode: html_entity_decode(): Argument #1 ($string) must be of type string, null given
htmlspecialchars_decode: htmlspecialchars_decode(): Argument #1 ($string) must be of type string, null given
