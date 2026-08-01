--TEST--
stdlib htmlentities() Reflection defaults match Zend (#24970, ext/standard/basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('htmlentities');
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
    }
    echo "\n";
}
echo 'omit=', htmlentities('<>"\''), "\n";
echo 'named=', htmlentities(string: '<>"\'', flags: 11, encoding: null, double_encode: true), "\n";
?>
--EXPECT--
required=1 argc=4
return=string
string:string REQ
flags:int OPT=11
encoding:?string OPT=NULL
double_encode:bool OPT=true
omit=&lt;&gt;&quot;&#039;
named=&lt;&gt;&quot;&#039;
