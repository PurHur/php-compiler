--TEST--
Language: array_keys((array)$ArrayObject) after asort — Cast ARG_SEND, try-invariant (#28822)
--FILE--
<?php
$a = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
$a->asort();
echo implode(',', array_keys((array) $a)), "\n";
try {
    echo implode(',', array_keys((array) $a)), "\n";
} catch (Throwable $e) {
    echo 'err:', $e->getMessage(), "\n";
}
$k = array_keys((array) $a);
echo implode(',', $k), "\n";
var_export(array_keys((array) (object) ['p' => 1, 'q' => 2]));
echo "\n";
?>
--EXPECT--
a,b,c
a,b,c
a,b,c
array (
  0 => 'p',
  1 => 'q',
)
