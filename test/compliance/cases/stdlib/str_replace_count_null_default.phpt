--TEST--
str_replace/str_ireplace Reflection $count default null (#24886, ext/standard/string.stub.php)
--FILE--
<?php
foreach (['str_replace', 'str_ireplace'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[3];
    $t = $p->getType();
    echo $fn, ' ', $p->getName(),
        ' type=', null !== $t ? (string) $t : 'none',
        ' allowsNull=', (int) $p->allowsNull(),
        ' def=', var_export($p->getDefaultValue(), true),
        PHP_EOL;
}
$c = -1;
echo 'result=', str_replace('a', 'b', 'aa', $c), ' count=', $c, PHP_EOL;
?>
--EXPECT--
str_replace count type=none allowsNull=1 def=NULL
str_ireplace count type=none allowsNull=1 def=NULL
result=bb count=2
