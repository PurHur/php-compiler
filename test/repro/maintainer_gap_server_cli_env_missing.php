<?php

declare(strict_types=1);

/**
 * Maintainer gap: CLI $_SERVER mirrors process environ (#14209, php-src-strict).
 */
foreach (['PATH', 'HOME', 'PWD', 'HOSTNAME'] as $key) {
    $fromGetenv = getenv($key);
    $fromServer = $_SERVER[$key] ?? null;
    if (false === $fromGetenv) {
        continue;
    }
    if ($fromServer !== $fromGetenv) {
        fwrite(
            STDERR,
            $key.' mismatch: getenv='.var_export($fromGetenv, true)
            .' $_SERVER='.var_export($fromServer, true)."\n"
        );
        exit(1);
    }
}

echo "ok\n";
