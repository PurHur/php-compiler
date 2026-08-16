<?php

declare(strict_types=1);

/**
 * #29292 — under PROFILE=8.4, str_pad() empty $pad_string ValueError is "must not be empty"
 * (php-src 8.4+ zend_argument_must_not_be_empty_error). Default/8.2 uses
 * "must be a non-empty string" — see #29755 / issue_29755_str_pad_null_message.php.
 */
putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';

$expected = 'str_pad(): Argument #3 ($pad_string) must not be empty';

try {
    str_pad('a', 5, '');
    fwrite(STDERR, "fail: str_pad(..., '') expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'empty:', $e->getMessage(), "\n";
}

echo "ok\n";
