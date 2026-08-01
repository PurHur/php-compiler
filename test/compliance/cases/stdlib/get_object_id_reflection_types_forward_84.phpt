--TEST--
get_object_id() Reflection object→int + named object: (#26210, ext/standard/basic_functions.stub.php, PROFILE=8.4)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionFunction('get_object_id');
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
$id = get_object_id($o);
echo get_object_id(object: $o) === $id ? "named_ok\n" : "named_mismatch\n";
try {
    get_object_id(obj: $o);
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
