<?php

/**
 * Repro #20693 / #21536 — DateTime::format / date_format(null) soft-null under PROFILE=8.4.
 *
 * Zend 8.4 emits E_DEPRECATED and coerces null→'' (not TypeError). #20693 asked for
 * TypeError; #21536 restores php-src-strict polarity.
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20693_datetime_format_null_84.php
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
    'date_format' => static fn () => date_format(date_create('2020-01-01'), null),
] as $name => $call) {
    try {
        $r = $call();
        echo "{$name}: OK ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo "{$name}: TypeError\n";
    }
}
