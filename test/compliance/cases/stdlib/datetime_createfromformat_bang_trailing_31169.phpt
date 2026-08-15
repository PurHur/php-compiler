--TEST--
stdlib DateTime::createFromFormat ! reset + trailing junk (ext/date/lib/parse_date.re, #31169)
--FILE--
<?php
declare(strict_types=1);
$trail = DateTime::createFromFormat('Y-m-d!', '2024-01-02x');
echo 'bang_trail_false=', false === $trail ? '1' : '0', "\n";
$err = DateTime::getLastErrors();
echo 'error_count=', (string) ($err['error_count'] ?? -1), "\n";
echo 'slot10=', $err['errors'][10] ?? '', "\n";
$ok = DateTime::createFromFormat('Y-m-d!', '2020-01-15');
echo 'bang_ok=', $ok ? $ok->format('Y-m-d H:i:s') : 'false', "\n";
$lead = DateTime::createFromFormat('!Y-m-d', '2020-01-15');
echo 'bang_lead=', $lead ? $lead->format('Y-m-d H:i:s') : 'false', "\n";
$pipe = DateTime::createFromFormat('Y-m-d|', '2024-01-02x');
echo 'pipe_trail_false=', false === $pipe ? '1' : '0', "\n";
$pipeErr = DateTime::getLastErrors();
echo 'pipe_slot10=', $pipeErr['errors'][10] ?? '', "\n";
$plain = DateTime::createFromFormat('Y-m-d', '2024-01-02x');
echo 'plain_trail_false=', false === $plain ? '1' : '0', "\n";
$plainErr = DateTime::getLastErrors();
echo 'plain_slot10=', $plainErr['errors'][10] ?? '', "\n";
$imm = DateTimeImmutable::createFromFormat('Y-m-d!', '2024-01-02x');
echo 'imm_trail_false=', false === $imm ? '1' : '0', "\n";
$immErr = DateTimeImmutable::getLastErrors();
echo 'imm_slot10=', $immErr['errors'][10] ?? '', "\n";
--EXPECT--
bang_trail_false=1
error_count=1
slot10=Trailing data
bang_ok=1970-01-01 00:00:00
bang_lead=2020-01-15 00:00:00
pipe_trail_false=1
pipe_slot10=Trailing data
plain_trail_false=1
plain_slot10=Trailing data
imm_trail_false=1
imm_slot10=Trailing data
