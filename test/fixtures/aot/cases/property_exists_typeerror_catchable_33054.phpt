--TEST--
AOT: property_exists() TypeError is catchable in try/catch (#33054, ext/standard/class.c)
--FILE--
<?php
try {
    property_exists(false, 'x');
    echo "no throw\n";
} catch (TypeError $e) {
    echo "caught\n";
}
try {
    property_exists(42, 'x');
} catch (TypeError $e) {
    echo "caught-int\n";
}
--EXPECT--
caught
caught-int
