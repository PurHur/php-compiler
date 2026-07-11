--TEST--
Regression: get_declared_classes() nested in in_array() after get_class() — haystack array not bool (#17882, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$anon = new class {
};

echo in_array(get_class($anon), get_declared_classes(), true) ? "yes\n" : "no\n";
echo in_array('stdClass', get_declared_classes(), true) ? "stdClass yes\n" : "stdClass no\n";
--EXPECT--
yes
stdClass yes
