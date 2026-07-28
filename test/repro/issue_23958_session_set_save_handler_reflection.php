<?php
/**
 * #23958 — session_set_save_handler Reflection + named 9-callback form.
 * Register before any output (headers-sent blocks save-handler change).
 */
$ok = session_set_save_handler(
    open: static fn (string $p, string $n): bool => true,
    close: static fn (): bool => true,
    read: static fn (string $i): string => '',
    write: static fn (string $i, string $d): bool => true,
    destroy: static fn (string $i): bool => true,
    gc: static fn (int $m): int => 0,
    create_sid: static fn (): string => 'ABCDEFGHIJKLMNOPQRSTUVWX12',
    validate_sid: static fn (string $i): bool => true,
    update_timestamp: static fn (string $i, string $d): bool => true
);

$r = new ReflectionFunction('session_set_save_handler');
echo 'count=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), $p->isOptional() ? ' opt' : ' req', "\n";
}
echo 'named9=', $ok ? 'true' : 'false', "\n";
