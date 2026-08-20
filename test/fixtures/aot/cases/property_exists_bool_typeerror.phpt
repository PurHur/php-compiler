--TEST--
AOT: property_exists() TypeError on boxed bool/null/int matches Zend given-type (#33054 leftover)
--FILE--
<?php
foreach ([false, true, null, 42] as $v) {
    try {
        var_export(property_exists($v, 'x'));
        echo " NO_THROW\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
TypeError:property_exists(): Argument #1 ($object_or_class) must be of type object|string, bool given
TypeError:property_exists(): Argument #1 ($object_or_class) must be of type object|string, bool given
TypeError:property_exists(): Argument #1 ($object_or_class) must be of type object|string, null given
TypeError:property_exists(): Argument #1 ($object_or_class) must be of type object|string, int given
