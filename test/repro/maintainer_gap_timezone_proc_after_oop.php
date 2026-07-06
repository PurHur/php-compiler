<?php

declare(strict_types=1);

$all = DateTimeZone::listIdentifiers();
$us = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');
echo \count($us), "\n";
$proc = timezone_identifiers_list();
echo \count($proc) === \count($all) ? "proc_sync\n" : "proc_mismatch\n";
