<?php
/**
 * Repro #24504 — msgfmt_format_message Reflection $values + named values:.
 * php-src ext/intl/msgformat/msgformat.stub.php
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_24504_msgfmt_format_message_values.php'
 */
if (!function_exists('msgfmt_format_message')) {
    echo "MISSING\n";
    exit(0);
}
$rf = new ReflectionFunction('msgfmt_format_message');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
try {
    echo 'values=', msgfmt_format_message(locale: 'en_US', pattern: 'Hi {0}', values: ['Ada']), "\n";
} catch (Throwable $e) {
    echo 'values:', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    msgfmt_format_message(locale: 'en_US', pattern: 'Hi {0}', args: ['Ada']);
    echo "legacy_args accepted\n";
} catch (Throwable $e) {
    echo 'args:', $e->getMessage(), "\n";
}
echo 'pos=', msgfmt_format_message('en_US', 'Hi {0}', ['Ada']), "\n";
