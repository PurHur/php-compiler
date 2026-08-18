<?php
/**
 * Repro #25230 — MessageFormatter::format Reflection $values + named values:.
 * php-src ext/intl/msgformat/msgformat.stub.php
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_25230_msgfmt_format_reflection_values.php'
 */
if (!class_exists('MessageFormatter')) {
    echo "MISSING\n";
    exit(0);
}
$rf = new ReflectionMethod(MessageFormatter::class, 'format');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$fmt = MessageFormatter::create('en_US', '{0}');
try {
    echo 'values=', $fmt->format(values: ['x']), "\n";
} catch (Throwable $e) {
    echo 'values:', $e->getMessage(), "\n";
}
try {
    $fmt->format(args: ['x']);
    echo "legacy_args accepted\n";
} catch (Throwable $e) {
    echo 'args:', $e->getMessage(), "\n";
}
echo 'pos=', $fmt->format(['x']), "\n";
