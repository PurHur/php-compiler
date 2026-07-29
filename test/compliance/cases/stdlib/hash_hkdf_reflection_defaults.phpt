--TEST--
stdlib hash_hkdf Reflection optional defaults + types (#25018, ext/hash/hash.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('hash_hkdf');
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
echo 'two_arg_len=', strlen(hash_hkdf('sha256', 'ikm')), "\n";
echo 'named_len=', strlen(hash_hkdf(algo: 'sha256', key: 'ikm', length: 8)), "\n";
?>
--EXPECT--
required=2 argc=5
algo:string REQ
key:string REQ
length:int OPT=0
info:string OPT=""
salt:string OPT=""
two_arg_len=32
named_len=8
