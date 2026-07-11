<?php

declare(strict_types=1);

enum UnitE
{
    case Alpha;
    case Beta;
}

enum BackedE: string
{
    case One = '1';
    case Two = '2';
}

$namesUnit = array_column(UnitE::cases(), 'name');
$namesBacked = array_column(BackedE::cases(), 'name');
$valuesBacked = array_column(BackedE::cases(), 'value');

if ($namesUnit !== ['Alpha', 'Beta']) {
    fwrite(STDERR, "unit name: expected [Alpha, Beta], got ".var_export($namesUnit, true)."\n");
    exit(1);
}
if ($namesBacked !== ['One', 'Two']) {
    fwrite(STDERR, "backed name: expected [One, Two], got ".var_export($namesBacked, true)."\n");
    exit(1);
}
if ($valuesBacked !== ['1', '2']) {
    fwrite(STDERR, "backed value: expected [1, 2], got ".var_export($valuesBacked, true)."\n");
    exit(1);
}

echo "ok\n";
