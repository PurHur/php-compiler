--TEST--
stdlib extension_loaded() — enum case operand TypeError (#5817, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum Ext: string { case Std = 'standard'; }

try {
    extension_loaded(Ext::Std);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

var_dump(extension_loaded('standard'));
?>
--EXPECT--
extension_loaded(): Argument #1 ($extension) must be of type string, Ext given
bool(true)
