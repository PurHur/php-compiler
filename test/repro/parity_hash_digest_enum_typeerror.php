<?php
declare(strict_types=1);

/** Issue #5780 — digest builtins must TypeError on enum case operands. */
enum E: string { case A = 'x'; }

foreach ([
    ['hash', fn () => hash('sha256', E::A)],
    ['hash_hmac', fn () => hash_hmac('sha256', E::A, 'key')],
    ['md5', fn () => md5(E::A)],
    ['sha1', fn () => sha1(E::A)],
    ['crc32', fn () => crc32(E::A)],
    ['bin2hex', fn () => bin2hex(E::A)],
    ['base64_encode', fn () => base64_encode(E::A)],
] as [$name, $call]) {
    try {
        $call();
        echo "$name: no throw\n";
    } catch (Throwable $e) {
        echo "$name: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
