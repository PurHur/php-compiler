<?php
/**
 * Repro #21536 — DateTime::format / date_format(null) soft-null DEP+'' under PROFILE=8.4.
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21536_datetime_format_null_soft84.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    if (E_DEPRECATED === $n) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach ([
    'DateTime::format' => static fn () => (new DateTime('2020-01-01'))->format(null),
    'DateTimeImmutable::format' => static fn () => (new DateTimeImmutable('2020-01-01'))->format(null),
    'date_format' => static fn () => date_format(date_create('2020-01-01'), null),
] as $name => $call) {
    try {
        $r = $call();
        echo "{$name}: OK ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "{$name}: ", get_class($e), "\n";
    }
}
echo "ALL_OK\n";
