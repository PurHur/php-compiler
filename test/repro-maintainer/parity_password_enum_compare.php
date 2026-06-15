<?php

declare(strict_types=1);

enum E: string {
    case A = 'secret';
}

function try_call(string $label, callable $fn): void {
    try {
        $fn();
        echo $label, ": uncaught\n";
    } catch (TypeError $e) {
        echo $label, ": TypeError\n";
    }
}

try_call('verify', fn () => password_verify(E::A, 'x'));
try_call('hash', fn () => password_hash(E::A, PASSWORD_DEFAULT));
try_call('needs_rehash', fn () => password_needs_rehash(E::A, PASSWORD_BCRYPT));
