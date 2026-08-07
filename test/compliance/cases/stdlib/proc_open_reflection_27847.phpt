--TEST--
stdlib proc_open Reflection pipes/optionals/no return (#27847, proc_open.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('proc_open');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), '|', $p->hasType() ? (string) $p->getType() : 'none',
        '|byRef=', $p->isPassedByReference() ? 1 : 0,
        '|opt=', $p->isOptional() ? 1 : 0, PHP_EOL;
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
$env = null;
foreach ($r->getParameters() as $i => $p) {
    if ('env_vars' === $p->getName()) {
        $env = $i;
        break;
    }
}
echo 'env_vars_idx=', $env, PHP_EOL;
?>
--EXPECT--
command|array|string|byRef=0|opt=0
descriptor_spec|array|byRef=0|opt=0
pipes|none|byRef=1|opt=0
cwd|?string|byRef=0|opt=1
env_vars|?array|byRef=0|opt=1
options|?array|byRef=0|opt=1
return=none
env_vars_idx=4
