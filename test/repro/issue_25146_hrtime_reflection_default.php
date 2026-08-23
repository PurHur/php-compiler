<?php
// #25146 — hrtime Reflection as_number optional default false (basic_functions.stub.php).
$p = (new ReflectionFunction('hrtime'))->getParameters()[0];
echo 'optional=', $p->isOptional() ? 'yes' : 'no';
if ($p->isDefaultValueAvailable()) {
    echo ' def=', var_export($p->getDefaultValue(), true);
}
echo "\n";
$pair = hrtime();
echo 'pair_len=', is_array($pair) ? count($pair) : 'n/a';
echo "\n";
