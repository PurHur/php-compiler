--TEST--
DateTime(Immutable)::setISODate(null,null,null) soft-null → -0001-12-26 (#31620, ext/date/php_date.c)
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        ++$deps;

        return true;
    }

    return false;
});

$d = new DateTime('2024-06-15', new DateTimeZone('UTC'));
$d->setISODate(null, null, null);
echo 'DateTime=', $d->format('Y-m-d'), "\n";

$i = new DateTimeImmutable('2024-06-15', new DateTimeZone('UTC'));
echo 'DateTimeImmutable=', $i->setISODate(null, null, null)->format('Y-m-d'), "\n";
echo 'deps=', $deps, "\n";
?>
--EXPECT--
DateTime=-0001-12-26
DateTimeImmutable=-0001-12-26
deps=6
