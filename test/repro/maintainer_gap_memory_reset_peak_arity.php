<?php
/**
 * Repro #26104 — memory_reset_peak_usage arity 0 + void Reflection.
 *
 * VM:  php bin/vm.php test/repro/maintainer_gap_memory_reset_peak_arity.php
 * JIT: php bin/jit.php test/repro/maintainer_gap_memory_reset_peak_arity.php
 * AOT zero-arg: php bin/compile.php -o /tmp/mrpu_ok -r 'memory_reset_peak_usage(); echo "zero_ok\n";' && /tmp/mrpu_ok
 * AOT 1-arg (uncaught): php bin/compile.php -o /tmp/mrpu_bad test/repro/maintainer_gap_memory_reset_peak_arity_aot_bad.php
 */
error_reporting(E_ALL);
try {
    memory_reset_peak_usage(true);
    echo "1arg_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$r = new ReflectionFunction('memory_reset_peak_usage');
echo 'arity=', $r->getNumberOfParameters(),
    ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
memory_reset_peak_usage();
echo "zero_ok\n";
