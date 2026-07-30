--TEST--
json_decode/json_encode Reflection stubs match php-src (#25458, ext/json/json.stub.php)
--FILE--
<?php
foreach (['json_decode', 'json_encode'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo '== ', $fn, " ==\n";
    echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(),
            ' type=', $p->hasType() ? (string) $p->getType() : '?',
            ' opt=', $p->isOptional() ? 'Y' : 'N';
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
echo 'decode=', json_encode(json_decode('{"a":1}', associative: true)), "\n";
echo 'encode=', var_export(json_encode(value: ['x' => 1]), true), "\n";
?>
--EXPECT--
== json_decode ==
ret=mixed
  json type=string opt=N
  associative type=?bool opt=Y def=NULL
  depth type=int opt=Y def=512
  flags type=int opt=Y def=0
== json_encode ==
ret=string|false
  value type=mixed opt=N
  flags type=int opt=Y def=0
  depth type=int opt=Y def=512
decode={"a":1}
encode='{"x":1}'
