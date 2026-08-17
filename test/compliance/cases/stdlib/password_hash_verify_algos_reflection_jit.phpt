--TEST--
password_hash/password_verify/password_algos Reflection stubs (JIT, issue #28917)
--FILE--
<?php
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
?>
--EXPECT--
password_verify(string $password, string $hash): bool
password_verify_req=2
password_algos(): array
password_algos_req=0
password_hash(string $password, string|int|null $algo, array $options=): string
password_hash_req=2
options_default=[]
verify_named=0
algos_ok
