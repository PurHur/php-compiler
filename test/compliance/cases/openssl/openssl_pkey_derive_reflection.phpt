--TEST--
openssl_pkey_derive Reflection + Zend named args (VM, issue #27685)
--FILE--
<?php
$r = new ReflectionFunction('openssl_pkey_derive');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
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
try {
    $v = openssl_pkey_derive(public_key: 'x', private_key: 'y');
    echo 'named=', var_export($v, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    openssl_pkey_derive(peer_key: 'x', private_key: 'y');
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
required=2 argc=3
ret=string|false
public_key REQ
private_key REQ
key_length:int OPT=0
named=false
Unknown named parameter $peer_key
