<?php
// Repro for #7276 — DateError / DateObjectError / DateRangeError hierarchy (ext/date/php_date.h).

echo 'DateError: ', class_exists('DateError', false) ? 'yes' : 'no', "\n";
echo 'DateObjectError: ', class_exists('DateObjectError', false) ? 'yes' : 'no', "\n";
echo 'DateRangeError: ', class_exists('DateRangeError', false) ? 'yes' : 'no', "\n";
echo 'DateObjectError sub DateError: ', is_subclass_of('DateObjectError', 'DateError') ? 'yes' : 'no', "\n";
echo 'DateRangeError sub DateError: ', is_subclass_of('DateRangeError', 'DateError') ? 'yes' : 'no', "\n";

try {
    throw new DateRangeError('Epoch doesn\'t fit in a PHP integer');
} catch (DateError $e) {
    echo "catch DateError: ok\n";
}

class BadDateTime extends DateTime
{
    public function __construct()
    {
    }
}

try {
    $dt = new BadDateTime();
    $dt->getTimestamp();
    echo "uninit: no throw\n";
} catch (DateObjectError $e) {
    echo "uninit: DateObjectError\n";
}

$d = new DateTime('2020-01-15 12:00:00', new DateTimeZone('UTC'));
echo 'range: ', $d->getTimestamp() > 0 ? 'ok' : 'bad', "\n";
