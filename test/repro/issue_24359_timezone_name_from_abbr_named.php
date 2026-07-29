<?php
/**
 * Repro #24359 — timezone_name_from_abbr Zend stub named params.
 * php-src: ext/date/php_date.stub.php
 */
$rf = new ReflectionFunction('timezone_name_from_abbr');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ' ';
}
echo "\n";
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
