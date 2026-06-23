--TEST--
stdlib method_exists() null object_or_class TypeError (#10901, ext/standard/class.c)
--FILE--
<?php
try {
    method_exists(null, 'm');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo 'TypeError', "\n";
}
?>
--EXPECT--
TypeError
