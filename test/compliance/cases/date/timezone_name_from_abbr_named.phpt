--TEST--
timezone_name_from_abbr Reflection/named utcOffset isDST (issue #24359, php_date.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('timezone_name_from_abbr');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(' ', $names), "\n";
try {
    echo timezone_name_from_abbr(abbr: 'EST', utcOffset: -18000, isDST: 0), "\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    timezone_name_from_abbr(abbr: 'EST', gmtoffset: -18000, isdst: 0);
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy=', $e->getMessage(), "\n";
}
?>
--EXPECT--
abbr utcOffset isDST
America/New_York
legacy=Unknown named parameter $gmtoffset
