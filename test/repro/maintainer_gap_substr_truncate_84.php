<?php

declare(strict_types=1);

if ('8.4' !== getenv('PHP_COMPILER_PROFILE')) {
    fwrite(STDERR, "skip: requires PHP_COMPILER_PROFILE=8.4\n");
    exit(0);
}

$quiet = @substr('hello world', 0, 50, truncate: true);
if ('hello world' !== $quiet) {
    fwrite(STDERR, "substr_err truncate true got ".var_export($quiet, true)."\n");
    exit(1);
}

try {
    mb_substr('hello world', 0, 50, 'UTF-8', truncate: true);
    fwrite(STDERR, "mb_substr_err: expected ArgumentCountError for 5th arg\n");
    exit(1);
} catch (ArgumentCountError $e) {
    // php-src has no mb_substr $truncate — arity 4 only (#23603).
}

echo "ok\n";
