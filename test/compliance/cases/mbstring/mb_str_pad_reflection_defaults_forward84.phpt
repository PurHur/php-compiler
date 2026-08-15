--TEST--
mbstring mb_str_pad Reflection types + defaults forward 8.4 (#27618, re-#23805, ext/mbstring/mbstring.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionFunction('mb_str_pad');
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
echo 'named=', mb_str_pad(string: 'a', length: 5, pad_string: '.'), "\n";
echo 'omit=', mb_str_pad('a', 5), "\n";
?>
--EXPECT--
required=2 argc=5
return=string
string:string REQ
length:int REQ
pad_string:string OPT=' '
pad_type:int OPT=1
encoding:?string OPT=NULL
named=a....
omit=a    
