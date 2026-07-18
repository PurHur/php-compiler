<?php

/**
 * Repro #20693 — DateTime::format / date_format(null) TypeError under PROFILE=8.4.
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20693_datetime_format_null_84.php
 */
foreach ([
    'DateTime::format' => static fn () => (new DateTime('2020-01-01'))->format(null),
    'date_format' => static fn () => date_format(date_create('2020-01-01'), null),
] as $name => $call) {
    try {
        $call();
        echo "{$name}: COERCE\n";
    } catch (TypeError $e) {
        echo "{$name}: TypeError\n";
    }
}
