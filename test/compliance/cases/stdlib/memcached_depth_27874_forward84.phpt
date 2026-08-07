--TEST--
stdlib Memcached add/getMulti/incr depth methods (#27874)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
echo extension_loaded('memcached') ? '1' : '0', "\n";
$m = new ReflectionClass('Memcached');
foreach (['add','replace','append','prepend','getMulti','setMulti','deleteMulti','increment','decrement','cas','flush','touch'] as $meth) {
    echo $meth, '=', $m->hasMethod($meth) ? '1' : '0', "\n";
}
--EXPECT--
1
add=1
replace=1
append=1
prepend=1
getMulti=1
setMulti=1
deleteMulti=1
increment=1
decrement=1
cas=1
flush=1
touch=1
