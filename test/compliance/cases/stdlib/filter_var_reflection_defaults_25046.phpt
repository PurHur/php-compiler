--TEST--
stdlib filter_var Reflection FILTER_DEFAULT + options=0 (#25046, ext/filter/filter.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('filter_var');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->hasType()) {
        echo ':', $p->getType();
    }
    echo $p->isOptional() ? ' OPT' : ' REQ';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        echo '=?';
    }
    echo "\n";
}
echo 'FILTER_DEFAULT=', FILTER_DEFAULT, "\n";
var_export(filter_var('x'));
echo "\n";
?>
--EXPECT--
required=1 argc=3
return=mixed
value:mixed REQ
filter:int OPT=516
options:array|int OPT=0
FILTER_DEFAULT=516
'x'
