--TEST--
mb_convert_encoding() Reflection array|string unions + |false return (#26466, ext/mbstring/mbstring.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('mb_convert_encoding');
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
var_dump(mb_convert_encoding(string: 'A', to_encoding: 'UTF-8'));
var_dump(mb_convert_encoding(['A', 'B'], 'UTF-8', 'ASCII'));
?>
--EXPECT--
required=2 argc=3
return=array|string|false
string:array|string REQ
to_encoding:string REQ
from_encoding:array|string|null OPT=NULL
string(1) "A"
array(2) {
  [0]=>
  string(1) "A"
  [1]=>
  string(1) "B"
}
