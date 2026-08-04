--TEST--
stdlib preg_grep/preg_match_all Reflection return |false (#26324)
--FILE--
<?php
foreach (['preg_grep', 'preg_match_all'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', (string) $r->getReturnType(), "\n";
}
var_export(preg_grep('/a/', ['a' => 'a', 'b' => 'b']));
echo "\n";
echo preg_match_all('/./', 'ab', $m), "\n";
?>
--EXPECT--
preg_grep ret=array|false
preg_match_all ret=int|false
array (
  'a' => 'a',
)
2
