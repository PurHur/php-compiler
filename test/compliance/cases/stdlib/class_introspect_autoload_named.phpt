--TEST--
Stdlib: class_parents/class_implements/class_uses — autoload: named parameter (issue #9172, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

var_export(class_parents('stdClass', autoload: false));
echo "\n";
var_export(class_implements('Iterator', autoload: false));
echo "\n";
var_export(class_uses('stdClass', autoload: false));
echo "\n";
try {
    class_parents('stdClass', autoload: []);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
array (
)
array (
  'Traversable' => 'Traversable',
)
array (
)
TypeError: class_parents(): Argument #2 ($autoload) must be of type bool, array given
