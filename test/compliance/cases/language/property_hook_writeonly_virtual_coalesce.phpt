--TEST--
Virtual set-only property: read/isset/?? throw write-only Error (#29240, zend_object_handlers.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Hso {
    public $x {
        set {}
    }
}
$o = new Hso();
try {
    echo $o->x, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump(isset($o->x));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump(empty($o->x));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo ($o->x ?? 'default'), "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Property Hso::$x is write-only
Error: Property Hso::$x is write-only
Error: Property Hso::$x is write-only
Error: Property Hso::$x is write-only
