--TEST--
stdlib hash Reflection optional binary/options defaults (#25068, ext/hash/hash.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('hash');
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
echo 'digest=', hash('sha256', 'x'), "\n";
echo 'named=', hash(algo: 'sha256', data: 'x', options: []), "\n";
?>
--EXPECT--
required=2 argc=4
algo:string REQ
data:string REQ
binary:bool OPT=false
options:array OPT=[]
digest=2d711642b726b04401627ca9fbac32f5c8530fb1903cc4db02258717921a4881
named=2d711642b726b04401627ca9fbac32f5c8530fb1903cc4db02258717921a4881
