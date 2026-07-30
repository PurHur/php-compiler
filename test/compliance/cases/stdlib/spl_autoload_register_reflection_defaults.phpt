--TEST--
stdlib spl_autoload_register Reflection ?callable=null throw=true (#25390, ext/spl/spl.stub.php)
--FILE--
<?php
declare(strict_types=1);

$rf = new ReflectionFunction('spl_autoload_register');
echo 'arity=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
$parts = [];
foreach ($rf->getParameters() as $p) {
    $parts[] = $p->getName()
        .':'
        .($p->hasType() ? (string) $p->getType() : '-')
        .':'.($p->isOptional() ? 'OPT' : 'REQ')
        .':'.($p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-');
}
echo 'params=', implode(',', $parts), "\n";
--EXPECT--
arity=3 req=0
params=callback:?callable:OPT:null,throw:bool:OPT:true,prepend:bool:OPT:false
