<?php

declare(strict_types=1);

$us = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');
$n = \count($us);
$proc = timezone_identifiers_list();
if (!\is_array($proc)) {
    fwrite(STDERR, 'proc type: '.\gettype($proc).' value: '.var_export($proc, true)."\n");
    exit(1);
}
try {
  $c = \count($proc);
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
if ($c !== \count(DateTimeZone::listIdentifiers())) {
    fwrite(STDERR, "count mismatch $c\n");
    exit(1);
}
echo "ok\n";
