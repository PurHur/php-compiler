--TEST--
Stdlib: session_set_save_handler Reflection + named validate_sid/update_timestamp (#23958)
--FILE--
<?php
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
echo $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), $p->isOptional() ? "=\n" : "\n";
}
echo $ok ? "named9=ok\n" : "named9=fail\n";
--EXPECT--
9
open
close=
read=
write=
destroy=
gc=
create_sid=
validate_sid=
update_timestamp=
named9=ok
