<?php
/**
 * Repro #27705 — normalizer_get_raw_decomposition Reflection must match
 * php-src normalizer.stub.php: (string $string, int $form = FORM_C): ?string
 * + named string:/form:.
 *
 *   Host needs php-intl (extension_loaded('intl')).
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_27705_normalizer_get_raw_decomposition_reflection.php'
 */
if (!function_exists('normalizer_get_raw_decomposition')) {
    echo "MISSING\n";
    exit(0);
}
$r = new ReflectionFunction('normalizer_get_raw_decomposition');
$params = [];
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    $line = $p->getName().':'.($t ? (string) $t : 'none');
    if ($p->isOptional()) {
        $line .= ' opt';
        if ($p->isDefaultValueAvailable()) {
            $line .= '='.var_export($p->getDefaultValue(), true);
        }
    }
    $params[] = $line;
}
$rt = $r->getReturnType();
echo 'arity='.$r->getNumberOfParameters().'|'.implode(',', $params).'|'.($rt ? (string) $rt : 'none')."\n";
try {
    echo 'named='.var_export(normalizer_get_raw_decomposition(string: "\xC3\xA9"), true)."\n";
} catch (Throwable $e) {
    echo 'named ERR='.$e->getMessage()."\n";
}
