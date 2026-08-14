<?php

declare(strict_types=1);

/**
 * Repro for #29921 — msgfmt_format_message(null, …) under strict_types must TypeError.
 *
 * Host Zend (extension_loaded('intl')) vs php bin/vm.php / bin/jit.php.
 */
foreach ([
    'locale' => static fn () => msgfmt_format_message(null, 'Hi {0}', [1]),
    'pattern' => static fn () => msgfmt_format_message('en', null, [1]),
    'static_locale' => static fn () => MessageFormatter::formatMessage(null, 'Hi {0}', [1]),
    'static_pattern' => static fn () => MessageFormatter::formatMessage('en', null, [1]),
] as $label => $fn) {
    try {
        $r = $fn();
        echo $label, ': ACCEPTED ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
