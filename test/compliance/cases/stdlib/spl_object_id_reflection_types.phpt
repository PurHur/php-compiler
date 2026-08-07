--TEST--
spl_object_id() Reflection object→int + named object: (#27707, re-#24569, ext/spl/spl.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('spl_object_id');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->hasType()) {
        echo ':', $p->getType();
    }
    echo $p->isOptional() ? ' OPT' : ' REQ';
    echo "\n";
}
$o = new stdClass;
$id = spl_object_id($o);
echo spl_object_id(object: $o) === $id ? "named_ok\n" : "named_mismatch\n";
try {
    spl_object_id(obj: $o);
    echo "obj accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
required=1 argc=1
return=int
object:object REQ
named_ok
Unknown named parameter $obj
