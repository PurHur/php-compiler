<?php
/**
 * #28742 — hash_update Reflection return true under PROFILE≥8.4 (hash.stub.php).
 *
 * Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28742_hash_update_reflection.php
 */
$r = new ReflectionFunction('hash_update');
echo (string) $r->getReturnType(), "\n";
foreach ($r->getParameters() as $p) {
    echo ($p->hasType() ? (string) $p->getType() : '-'), ' $', $p->getName(), "\n";
}

$ctx = hash_init('md5');
$ok = hash_update($ctx, 'x');
echo 'runtime=', true === $ok ? 'true' : var_export($ok, true), "\n";
echo 'digest=', hash_final($ctx), "\n";
