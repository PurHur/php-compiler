--TEST--
fscanf Reflection return + mixed &...$vars (#26058, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('fscanf');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(),
        ' type=', $p->hasType() ? (string) $p->getType() : 'NONE',
        ' byref=', $p->isPassedByReference() ? 'y' : 'n',
        ' variadic=', $p->isVariadic() ? 'y' : 'n', "\n";
}
?>
--EXPECT--
return=array|int|false|null
stream type=NONE byref=n variadic=n
format type=string byref=n variadic=n
vars type=mixed byref=y variadic=y
