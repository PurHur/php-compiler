--TEST--
stdlib intl Collator skeleton withheld; IntlDateFormatter pattern create (#5925, #19549)
--FILE--
<?php
echo 'collator=', var_export(class_exists('Collator', false), true), "\n";
echo 'formatter=', var_export(class_exists('IntlDateFormatter', false), true), "\n";
try {
    Collator::create('en_US');
    echo "collator_no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$f = IntlDateFormatter::create('en_US', 0, 0, 'UTC', 1, 'yyyy-MM-dd');
echo $f->format(1710000000), "\n";
?>
--EXPECT--
collator=false
formatter=true
Error: Class "Collator" not found
2024-03-09
