<?php
/**
 * Repro #25199 — idn_to_ascii()/idn_to_utf8() Reflection + named flags:
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_25199_idn_reflection.php'
 */
declare(strict_types=1);

if (!function_exists('idn_to_ascii') || !function_exists('idn_to_utf8')) {
    fwrite(STDERR, "skip: idn builtins not advertised\n");
    exit(0);
}

foreach (['idn_to_ascii', 'idn_to_utf8'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ':';
    foreach ($rf->getParameters() as $p) {
        echo ' ', $p->getName();
        if ($p->isOptional()) {
            try {
                echo '=', var_export($p->getDefaultValue(), true);
            } catch (Throwable $e) {
                echo '=UNAVAILABLE';
            }
        }
        if ($p->isPassedByReference()) {
            echo ':ref';
        }
    }
    echo "\n";
}

try {
    echo idn_to_ascii(domain: 'münchen.de', flags: IDNA_DEFAULT, variant: INTL_IDNA_VARIANT_UTS46), "\n";
} catch (Throwable $e) {
    echo 'err:', $e->getMessage(), "\n";
}

try {
    idn_to_ascii(domain: 'example.com', options: 0);
    echo "options_ok\n";
} catch (Throwable $e) {
    echo 'options:', $e->getMessage(), "\n";
}
