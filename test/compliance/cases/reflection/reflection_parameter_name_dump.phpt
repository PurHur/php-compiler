--TEST--
ReflectionParameter::$name matches getName(); print_r/var_dump show name only (#22528)
--FILE--
<?php
class T
{
    public function m(int $a): void
    {
    }
}

function foo(string $b): void
{
}

$rp = new ReflectionParameter([T::class, 'm'], 'a');
echo $rp->getName(), "\n";
var_export($rp->name);
echo "\n";
echo 'eq=', ($rp->name === $rp->getName()) ? '1' : '0', "\n";
echo 'pe_name=', property_exists($rp, 'name') ? '1' : '0', "\n";
echo 'pe_paramName=', property_exists($rp, 'paramName') ? '1' : '0', "\n";
echo 'pe_funcName=', property_exists($rp, 'funcName') ? '1' : '0', "\n";
echo 'pe_paramClass=', property_exists($rp, 'paramClass') ? '1' : '0', "\n";
print_r($rp);
var_dump($rp);

$rf = new ReflectionParameter('foo', 'b');
echo $rf->getName(), "\n";
var_export($rf->name);
echo "\n";
print_r($rf);
?>
--EXPECTF--
a
'a'
eq=1
pe_name=1
pe_paramName=0
pe_funcName=0
pe_paramClass=0
ReflectionParameter Object
(
    [name] => a
)
object(ReflectionParameter)#%d (1) {
 ["name"]=>
 string(1) "a"
}
b
'b'
ReflectionParameter Object
(
    [name] => b
)
