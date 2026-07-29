--TEST--
spl_object_hash / spl_object_id Reflection object param + named object: (issue #24569)
--FILE--
<?php
foreach (['spl_object_hash', 'spl_object_id'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), ',req=', $rf->getNumberOfRequiredParameters(), "\n";
}
$o = new stdClass();
echo strlen(spl_object_hash(object: $o)) === strlen(spl_object_hash($o)) ? 'hash_named_ok' : 'hash_mismatch', "\n";
echo spl_object_id(object: $o) === spl_object_id($o) ? 'id_named_ok' : 'id_mismatch', "\n";
try {
    spl_object_hash(obj: $o);
    echo "obj accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
spl_object_hash:object,req=1
spl_object_id:object,req=1
hash_named_ok
id_named_ok
Unknown named parameter $obj
