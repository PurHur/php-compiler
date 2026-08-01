--TEST--
stdlib serialize/unserialize Zend stub named params + Reflection (#23260)
--FILE--
<?php
$namesOf = static function (string $fn): string {
    $r = new ReflectionFunction($fn);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    return implode(',', $names);
};
echo $namesOf('serialize'), "\n";
echo $namesOf('unserialize'), "\n";
$ser = new ReflectionFunction('serialize');
echo 'serialize value=', (string) $ser->getParameters()[0]->getType(), ' return=', (string) $ser->getReturnType(), "\n";
$uns = new ReflectionFunction('unserialize');
$opts = $uns->getParameters()[1];
echo 'unserialize data=', (string) $uns->getParameters()[0]->getType();
echo ' options=', (string) $opts->getType();
echo $opts->isOptional() ? ' OPT' : ' REQ';
if ($opts->isDefaultValueAvailable()) {
    echo '=', var_export($opts->getDefaultValue(), true);
}
echo ' return=', (string) $uns->getReturnType(), "\n";
echo serialize(value: [1]), "\n";
var_export(unserialize(data: 'i:1;'));
echo "\n";
var_export(unserialize(data: 'i:7;', options: []));
echo "\n";
try {
    serialize(variable: [1]);
    echo "variable accepted\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    unserialize(variable_representation: 'i:1;');
    echo "variable_representation accepted\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
value
data,options
serialize value=mixed return=string
unserialize data=string options=array OPT=array (
) return=mixed
a:1:{i:0;i:1;}
1
7
Unknown named parameter $variable
Unknown named parameter $variable_representation
