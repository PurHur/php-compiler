--TEST--
stdlib strtok() null token returns false; Reflection string/?string=null (#25171, php-src string.stub.php)
--FILE--
<?php
var_export(strtok('a.b.c', null));
echo "\n";
echo strtok('a.b.c', '') === 'a.b.c' ? "empty_ok\n" : "empty_bad\n";
$r = new ReflectionFunction('strtok');
foreach ($r->getParameters() as $p) {
    echo $p->getName(),
        ' type=', $p->hasType() ? (string) $p->getType() : '-',
        ' null=', ($p->hasType() && $p->getType()->allowsNull()) ? '1' : '0',
        ' opt=', $p->isOptional() ? '1' : '0';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
?>
--EXPECT--
false
empty_ok
string type=string null=0 opt=0
token type=?string null=1 opt=1 def=NULL
