--TEST--
json_decode Reflection $flags optional default 0 (#24812, ext/json/json.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('json_decode');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional(),
        ' defAvail=', (int) $p->isDefaultValueAvailable();
    if ($p->isDefaultValueAvailable()) {
        echo ' ', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
var_export(json_decode('{"a":1}', associative: true, flags: JSON_THROW_ON_ERROR));
echo "\n";
var_export(json_decode('{"a":1}', true, 512, JSON_THROW_ON_ERROR));
echo "\n";
?>
--EXPECT--
json opt=0 defAvail=0
associative opt=1 defAvail=1 NULL
depth opt=1 defAvail=1 512
flags opt=1 defAvail=1 0
array (
  'a' => 1,
)
array (
  'a' => 1,
)
