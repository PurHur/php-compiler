--TEST--
date DateTime::createFromFormat | / + modifiers (ext/date/lib/parse_date.re, #22836)
--FILE--
<?php
declare(strict_types=1);
$pipe = DateTime::createFromFormat('Y-m-d|', '2020-01-15');
echo 'pipe=', $pipe ? $pipe->format('Y-m-d H:i:s') : 'false', "\n";
$imm = DateTimeImmutable::createFromFormat('Y-m-d|', '2020-01-15');
echo 'imm=', $imm ? $imm->format('Y-m-d H:i:s') : 'false', "\n";
$plus = DateTime::createFromFormat('Y-m-d+', '2020-01-15');
echo 'plus_ok=', $plus instanceof DateTime ? '1' : '0', ' date=', $plus ? $plus->format('Y-m-d') : '', "\n";
$plusTrail = DateTime::createFromFormat('Y-m-d+', '2020-01-15 12:30:45');
echo 'plus_trail=', $plusTrail instanceof DateTime ? '1' : '0', ' date=', $plusTrail ? $plusTrail->format('Y-m-d') : '', "\n";
$bang = DateTime::createFromFormat('!Y-m-d', '2020-01-15');
echo 'bang=', $bang ? $bang->format('Y-m-d H:i:s') : 'false', "\n";
$noPlus = DateTime::createFromFormat('Y-m-d', '2020-01-15 extra');
echo 'no_plus=', false === $noPlus ? 'false' : 'ok', "\n";
--EXPECT--
pipe=2020-01-15 00:00:00
imm=2020-01-15 00:00:00
plus_ok=1 date=2020-01-15
plus_trail=1 date=2020-01-15
bang=2020-01-15 00:00:00
no_plus=false
