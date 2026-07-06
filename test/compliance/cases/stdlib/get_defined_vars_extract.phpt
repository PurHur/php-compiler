--TEST--
stdlib get_defined_vars() includes extract() imports (#4517, zend_get_defined_vars)
--FILE--
<?php
declare(strict_types=1);

function probe(): void {
    extract(['a' => 1, 'b' => 2]);
    $vars = get_defined_vars();
    ksort($vars);
    var_export($vars);
    echo "\n";
}

probe();
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
