<?php
// Repro #23207 — password_hash/password_verify Zend stub named parameters
$hashNames = [];
foreach ((new ReflectionFunction('password_hash'))->getParameters() as $p) {
    $hashNames[] = $p->getName();
}
$verifyNames = [];
foreach ((new ReflectionFunction('password_verify'))->getParameters() as $p) {
    $verifyNames[] = $p->getName();
}
$hNamed = password_hash(password: 'secret', algo: PASSWORD_DEFAULT);
$hPositional = password_hash('secret', PASSWORD_DEFAULT);
$ok = ['password', 'algo', 'options'] === $hashNames
    && ['password', 'hash'] === $verifyNames
    && is_string($hNamed)
    && password_verify(password: 'secret', hash: $hNamed)
    && password_verify(password: 'secret', hash: $hPositional)
    && !password_verify(password: 'wrong', hash: $hNamed);
echo $ok ? "ok\n" : "fail\n";
