--TEST--
stdlib iterator_to_array(null $preserve_keys) soft DEP+coerce outside strict_types (#31340, ext/spl/iterator.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    $a = iterator_to_array(new ArrayIterator(['a' => 1, 'b' => 2]), null);
    var_export($a);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: iterator_to_array(): Passing null to parameter #2 ($preserve_keys) of type bool is deprecated in %s on line %d
array (
  0 => 1,
  1 => 2,
)
