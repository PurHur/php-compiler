--TEST--
stdlib type probes — autoload: named parameter (#10071, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    is_subclass_of('stdClass', 'Traversable', autoload: false);
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    is_a(new stdClass(), 'stdClass', autoload: false);
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
echo class_exists('Nonexistent', autoload: false) ? 'yes' : 'no', "\n";
echo interface_exists('Traversable', autoload: false) ? 'yes' : 'no', "\n";
?>
--EXPECT--
Unknown named parameter $autoload
Unknown named parameter $autoload
no
yes
