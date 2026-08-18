<?php
/**
 * Repro #25497 — collator_compare()/collator_create() Reflection + named args:
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_25497_collator_compare_reflection.php'
 */
declare(strict_types=1);

if (!function_exists('collator_compare') || !function_exists('collator_create')) {
    fwrite(STDERR, "skip: collator builtins not advertised\n");
    exit(0);
}

foreach (['collator_compare', 'collator_create'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ' arity=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
    echo $fn, ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
    foreach ($rf->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', ($t ? (string) $t.' ' : ''), '$', $p->getName();
        echo $p->isOptional() ? ' OPT' : ' REQ';
        echo "\n";
    }
}

$c = Collator::create('en_US');
echo 'create_named=', (collator_create(locale: 'en_US') instanceof Collator) ? 'ok' : 'fail', "\n";
echo 'positional=', var_export(collator_compare($c, 'a', 'b'), true), "\n";
try {
    echo 'named=', var_export(collator_compare(object: $c, string1: 'a', string2: 'b'), true), "\n";
} catch (Throwable $e) {
    echo 'named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    collator_compare(coll: $c, str1: 'a', str2: 'b');
    echo "legacy_names accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
