--TEST--
json_validate() Reflection string/int/int→bool (#26211, ext/json/json.stub.php, PROFILE=8.4)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionFunction('json_validate');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->hasType()) {
        echo ':', $p->getType();
    }
    echo $p->isOptional() ? ' OPT' : ' REQ';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', json_encode($p->getDefaultValue());
    }
    echo "\n";
}
var_dump(json_validate(json: '{}'));
var_dump(json_validate('{"a":1}', depth: 512, flags: 0));
?>
--EXPECT--
required=1 argc=3
return=bool
json:string REQ
depth:int OPT=512
flags:int OPT=0
bool(true)
bool(true)
