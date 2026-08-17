<?php
// #28917 — password_verify/password_algos/password_hash Reflection vs Zend stubs
// (ext/standard/password.stub.php / basic_functions.stub.php).
foreach (['password_verify', 'password_algos', 'password_hash'] as $n) {
    $r = new ReflectionFunction($n);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '';
        $opt = $p->isOptional() ? '=' : '';
        $ps[] = trim($t.' $'.$p->getName().$opt);
    }
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : 'untyped';
    echo $n.'('.implode(', ', $ps).'): '.$ret, PHP_EOL;
    echo $n.'_req='.$r->getNumberOfRequiredParameters(), PHP_EOL;
}
$hash = new ReflectionFunction('password_hash');
$options = $hash->getParameters()[2];
echo 'options_default=', $options->isDefaultValueAvailable()
    ? json_encode($options->getDefaultValue())
    : 'NONE', PHP_EOL;
echo password_verify(password: 'x', hash: 'y') ? "verify_named=1\n" : "verify_named=0\n";
echo is_array(password_algos()) ? "algos_ok\n" : "algos_bad\n";
