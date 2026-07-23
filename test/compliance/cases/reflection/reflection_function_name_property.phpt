--TEST--
ReflectionFunction::$name matches getName(); var_dump shows name not funcName (#22488)
--FILE--
<?php
function foo(int $a): void
{
}

$rf = new ReflectionFunction('foo');
$rp = new ReflectionParameter('foo', 'a');
echo $rf->getName(), "\n";
var_export($rf->name);
echo "\n";
var_export($rp->name);
echo "\n";
echo 'pe_name=', property_exists($rf, 'name') ? '1' : '0', "\n";
echo 'pe_funcName=', property_exists($rf, 'funcName') ? '1' : '0', "\n";
echo 'eq=', ($rf->name === $rf->getName()) ? '1' : '0', "\n";
var_dump($rf);
print_r($rf);
?>
--EXPECTF--
foo
'foo'
'a'
pe_name=1
pe_funcName=0
eq=1
object(ReflectionFunction)#%d (1) {
 ["name"]=>
 string(3) "foo"
}
ReflectionFunction Object
(
    [name] => foo
)
