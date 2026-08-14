<?php

/**
 * Repro #30972 — createFromFormat invalid clock overflows + warning at input offset.
 * php-src: ext/date/lib/parse_date.c — timelib_parse_from_format
 */
function dump_err(array|false $e): void
{
    if (false === $e) {
        echo "err=false\n";

        return;
    }
    $keys = array_keys($e['warnings'] ?? []);
    $msg = $e['warnings'][$keys[0] ?? -1] ?? '';
    echo 'wc=', (string) ($e['warning_count'] ?? 0),
        ' key=', (string) ($keys[0] ?? ''),
        ' msg=', $msg, "\n";
}

$h25 = DateTime::createFromFormat('H:i', '25:00');
$e25 = DateTime::getLastErrors();
echo 'H:i 25:00 ', false === $h25 ? 'false' : $h25->format('H:i:s'),
    ' next=', (false !== $h25 && $h25->format('Y-m-d') !== date('Y-m-d')) ? '1' : '0',
    "\n";
dump_err($e25);

$i60 = DateTime::createFromFormat('H:i', '12:60');
echo 'H:i 12:60 ', false === $i60 ? 'false' : $i60->format('H:i:s'), "\n";
dump_err(DateTime::getLastErrors());

$imm = DateTimeImmutable::createFromFormat('H:i', '25:00');
echo 'imm ', false === $imm ? 'false' : $imm->format('H:i:s'), "\n";
dump_err(DateTimeImmutable::getLastErrors());

$fn = date_create_from_format('H:i', '25:00');
echo 'fn ', false === $fn ? 'false' : $fn->format('H:i:s'), "\n";
dump_err(date_get_last_errors());

$cal = DateTime::createFromFormat('Y-m-d H:i:s', '2024-02-31 12:00:00');
echo 'cal ', false === $cal ? 'false' : $cal->format('Y-m-d H:i:s'), "\n";
dump_err(DateTime::getLastErrors());

$bang = DateTime::createFromFormat('!H:i', '25:00');
echo 'bang ', false === $bang ? 'false' : $bang->format('Y-m-d H:i:s'), "\n";
dump_err(DateTime::getLastErrors());

$p = date_parse_from_format('H:i', '25:00');
echo 'parse h=', var_export($p['hour'], true), ' wc=', (string) $p['warning_count'],
    ' w5=', $p['warnings'][5] ?? '', "\n";
