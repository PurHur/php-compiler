<?php

declare(strict_types=1);

/** Maintainer repro for #13955 — unhandled enum match must throw UnhandledMatchError. */

enum E: int
{
    case A = 1;
    case B = 2;
}

try {
    match (E::A) {
        E::B => 'b',
    };
    echo "fail: no throw\n";
} catch (UnhandledMatchError $e) {
    echo 'ok: ', get_class($e), ': ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'fail: ', get_class($e), ': ', $e->getMessage(), "\n";
}
