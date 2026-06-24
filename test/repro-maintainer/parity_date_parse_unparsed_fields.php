<?php

declare(strict_types=1);

// Issue #11068 — date_parse() unparsed hour/minute/second must be false, not 0 (ext/standard/parsedate.c).

$p = date_parse('2024-01-01');
$hour = $p['hour'];
$minute = $p['minute'];
$second = $p['second'];
$fraction = $p['fraction'];

if (false !== $hour || false !== $minute || false !== $second || false !== $fraction) {
    fwrite(STDERR, "date-only: expected false for unparsed time fields\n");
    fwrite(STDERR, 'hour='.var_export($hour, true).' minute='.var_export($minute, true)
        .' second='.var_export($second, true).' fraction='.var_export($fraction, true)."\n");
    exit(1);
}

$p2 = date_parse('2024-01-01 12:30:45');
if (12 !== $p2['hour'] || 30 !== $p2['minute'] || 45 !== $p2['second']) {
    fwrite(STDERR, "datetime: expected 12/30/45\n");
    exit(1);
}

echo "ok\n";
