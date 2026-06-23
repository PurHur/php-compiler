--TEST--
stdlib createFromFormat() bang modifier and invalid calendar overflow (#10743, #10744)
--FILE--
<?php
$dt = DateTime::createFromFormat('!H:i', '14:30');
echo $dt->format('Y-m-d H:i:s'), "\n";
$dt2 = date_create_from_format('!H:i', '14:30');
echo $dt2->format('Y-m-d H:i:s'), "\n";

$bad = date_create_from_format('!Y-m-d', '2024-02-30');
var_export($bad !== false);
echo "\n";
if ($bad instanceof DateTimeInterface) {
    echo $bad->format('Y-m-d H:i:s'), "\n";
}
$errs = DateTime::getLastErrors();
var_export($errs['warning_count'] ?? null);
echo "\n";
var_export($errs['warnings'][10] ?? null);
echo "\n";
?>
--EXPECT--
1970-01-01 14:30:00
1970-01-01 14:30:00
true
2024-03-01 00:00:00
1
'The parsed date was invalid'
