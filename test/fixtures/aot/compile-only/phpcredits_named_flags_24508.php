<?php
// #24508 AOT: phpcredits(flags:) compiles; legacy flag: is catchable at runtime.
$rf = new ReflectionFunction('phpcredits');
$params = $rf->getParameters();
echo 'nparams=', $rf->getNumberOfParameters(), "\n";
echo 'name=', $params[0]->getName(), "\n";
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
ob_start();
$ok = phpcredits(flags: CREDITS_GENERAL);
ob_end_clean();
var_dump($ok);
try {
    phpcredits(flag: CREDITS_GENERAL);
    echo "legacy_flag_accepted\n";
} catch (Error $e) {
    echo 'legacy=', $e->getMessage(), "\n";
}
