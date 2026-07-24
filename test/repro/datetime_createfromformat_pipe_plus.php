<?php
declare(strict_types=1);
// #22836 — createFromFormat `|` resets unparsed fields; `+` allows trailing.
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
