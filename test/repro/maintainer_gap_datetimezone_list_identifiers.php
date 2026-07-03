<?php

declare(strict_types=1);

$ids = DateTimeZone::listIdentifiers();
if (!\is_array($ids) || 0 === \count($ids)) {
    fwrite(STDERR, "fail: empty list\n");
    exit(1);
}
if (!\in_array('UTC', $ids, true)) {
    fwrite(STDERR, "fail: no UTC\n");
    exit(1);
}

$eu = DateTimeZone::listIdentifiers(DateTimeZone::EUROPE);
if (0 === \count($eu)) {
    fwrite(STDERR, "fail: europe empty\n");
    exit(1);
}

$us = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');
if (0 === \count($us)) {
    fwrite(STDERR, "fail: US empty\n");
    exit(1);
}

$proc = timezone_identifiers_list();
if (\count($proc) !== \count($ids)) {
    fwrite(STDERR, 'fail: procedural count '.\count($proc).' vs oop '.\count($ids)."\n");
    exit(1);
}

echo "ok\n";
