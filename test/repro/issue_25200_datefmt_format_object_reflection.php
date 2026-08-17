<?php
/**
 * Repro #25200 — datefmt_format_object Reflection must match
 * php-src php_intl.stub.php: ($datetime, $format = null, ?string $locale = null): string|false
 * + named datetime:/format:/locale:.
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_25200_datefmt_format_object_reflection.php'
 */
if (!function_exists('datefmt_format_object')) {
    echo "MISSING\n";
    exit(0);
}
$dt = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
$r = new ReflectionFunction('datefmt_format_object');
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
    echo 'named='.datefmt_format_object(datetime: $dt, format: 'yyyy-MM-dd', locale: 'en_US')."\n";
} catch (Throwable $e) {
    echo 'named ERR='.$e->getMessage()."\n";
}
echo 'pos='.datefmt_format_object($dt, 'yyyy-MM-dd', 'en_US')."\n";
try {
    datefmt_format_object(object: $dt);
    echo "legacy_object accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage()."\n";
}
