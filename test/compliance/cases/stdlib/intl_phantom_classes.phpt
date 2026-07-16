--TEST--
stdlib intl Collator withheld; IntlDateFormatter partial advertise (#12115, #19549)
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'collator=', (int) class_exists('Collator', false), "\n";
echo 'formatter=', (int) class_exists('IntlDateFormatter', false), "\n";
try {
    Collator::create('en_US');
    echo "collator_no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$f = IntlDateFormatter::create('en_US', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'yyyy-MM-dd');
echo 'create_ok=', (int) ($f instanceof IntlDateFormatter), "\n";
echo $f->format(new DateTime('2024-01-02 00:00:00', new DateTimeZone('UTC'))), "\n";
?>
--EXPECT--
intl_loaded=0
collator=0
formatter=1
Error: Class "Collator" not found
create_ok=1
2024-01-02
