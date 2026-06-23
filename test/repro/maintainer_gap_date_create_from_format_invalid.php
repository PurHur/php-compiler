<?php
$dt = date_create_from_format('!Y-m-d', '2024-02-30');
var_export($dt !== false);
echo "\n";
if ($dt instanceof DateTimeInterface) {
    echo $dt->format('Y-m-d H:i:s'), "\n";
}
$errs = DateTime::getLastErrors();
var_export($errs['warning_count'] ?? null);
echo "\n";
var_export($errs['warnings'][10] ?? null);
echo "\n";
