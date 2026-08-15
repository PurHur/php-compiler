--TEST--
stdlib DateTime::createFromFormat + trailing warning bag (ext/date/lib/parse_date.re, #31170)
--FILE--
<?php
declare(strict_types=1);
$d = DateTime::createFromFormat('Y-m-d+', '2024-01-02x');
echo 'plus_trail_obj=', $d instanceof DateTime ? '1' : '0', "\n";
echo 'plus_trail_date=', $d ? $d->format('Y-m-d') : '', "\n";
$err = DateTime::getLastErrors();
echo 'warning_count=', (string) ($err['warning_count'] ?? -1), "\n";
echo 'slot10=', $err['warnings'][10] ?? '', "\n";
echo 'error_count=', (string) ($err['error_count'] ?? -1), "\n";
$ok = DateTime::createFromFormat('Y-m-d+', '2024-01-02');
echo 'plus_clean=', false === DateTime::getLastErrors() ? 'false' : 'bag', "\n";
$pipe = DateTime::createFromFormat('Y-m-d|', '2024-01-02x');
echo 'pipe_trail_false=', false === $pipe ? '1' : '0', "\n";
$pipeErr = DateTime::getLastErrors();
echo 'pipe_slot10=', $pipeErr['errors'][10] ?? '', "\n";
$both = DateTime::createFromFormat('Y-m-d+', '2024-02-30x');
echo 'plus_invalid_trail_wc=', (string) (DateTime::getLastErrors()['warning_count'] ?? -1), "\n";
echo 'plus_invalid_trail_slot=', DateTime::getLastErrors()['warnings'][10] ?? '', "\n";
$imm = DateTimeImmutable::createFromFormat('Y-m-d+', '2024-01-02x');
echo 'imm_obj=', $imm instanceof DateTimeImmutable ? '1' : '0', "\n";
echo 'imm_slot10=', DateTimeImmutable::getLastErrors()['warnings'][10] ?? '', "\n";
--EXPECT--
plus_trail_obj=1
plus_trail_date=2024-01-02
warning_count=1
slot10=Trailing data
error_count=0
plus_clean=false
pipe_trail_false=1
pipe_slot10=Trailing data
plus_invalid_trail_wc=2
plus_invalid_trail_slot=The parsed date was invalid
imm_obj=1
imm_slot10=Trailing data
