--TEST--
stdlib iterator_to_array Reflection Traversable|array + preserve_keys=true (#25066, ext/spl/spl.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('iterator_to_array');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
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
$it = new ArrayIterator(['a' => 1, 'b' => 2]);
echo 'keys=', json_encode(iterator_to_array($it)), "\n";
echo 'named=', json_encode(iterator_to_array(iterator: $it, preserve_keys: false)), "\n";
?>
--EXPECT--
required=1 argc=2
iterator:Traversable|array REQ
preserve_keys:bool OPT=true
keys={"a":1,"b":2}
named=[1,2]
