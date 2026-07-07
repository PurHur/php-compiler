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

$mbQuiet = mb_substr('hello world', 0, 50, 'UTF-8', truncate: true);
if ('hello world' !== $mbQuiet) {
    fwrite(STDERR, "mb_substr_err truncate true got ".var_export($mbQuiet, true)."\n");
    exit(1);
}

echo "ok\n";
