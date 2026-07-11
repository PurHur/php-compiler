--TEST--
stdlib get_defined_constants() category named param rejected on reference profile (#12947)
--FILE--
<?php
try {
    get_defined_constants(category: 'Core');
    echo "no throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Unknown named parameter $category
