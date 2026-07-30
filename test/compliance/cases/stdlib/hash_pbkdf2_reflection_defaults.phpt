--TEST--
stdlib hash_pbkdf2 Reflection optional defaults + types (#25469, ext/hash/hash.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('hash_pbkdf2');
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
$omit = hash_pbkdf2('sha256', 'p', 's', 1000);
$named = hash_pbkdf2(algo: 'sha256', password: 'p', salt: 's', iterations: 1000, length: 16);
echo 'arity4=', substr($omit, 0, 8), "\n";
echo 'named_len=', strlen($named), "\n";
?>
--EXPECT--
required=4 argc=7
return=string
algo:string REQ
password:string REQ
salt:string REQ
iterations:int REQ
length:int OPT=0
binary:bool OPT=false
options:array OPT=[]
arity4=07f5f5e2
named_len=16
