--TEST--
getenv Reflection defaults; named local_only without name (#24855)
--FILE--
<?php
putenv('PHPC_TEST_GE_NAMED=1');
$r = new ReflectionFunction('getenv');
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName();
    $t = $p->getType();
    echo ' type=', $t ? (string) $t : '(none)';
    echo ' opt=', (int) $p->isOptional();
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
$v = getenv(local_only: true);
echo is_array($v) ? 'named_local_only=array' : var_export($v, true), "\n";
$all = getenv();
echo 'zero_arg=array', "\n";
echo 'named_is_array=', is_array($v) ? '1' : '0', "\n";
echo 'match_zero=', (is_array($v) && is_array($all) && $v === $all) ? '1' : '0', "\n";
echo 'putenv_hit=', getenv('PHPC_TEST_GE_NAMED'), "\n";
--EXPECT--
$name type=?string opt=1 def=NULL
$local_only type=bool opt=1 def=false
named_local_only=array
zero_arg=array
named_is_array=1
match_zero=1
putenv_hit=1
