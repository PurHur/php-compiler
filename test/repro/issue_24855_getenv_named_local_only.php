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
echo 'match_zero=', (is_array($v) && is_array($all) && $v === $all) ? '1' : '0', "\n";
echo 'putenv_hit=', getenv('PHPC_TEST_GE_NAMED'), "\n";
