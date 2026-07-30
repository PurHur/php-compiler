<?php
declare(strict_types=1);

// Repro for #25486 — date_parse timezone abbreviation metadata vs Zend.
$p = date_parse('2020-01-02 03:04:05 UTC');
echo ($p['tz_abbr'] ?? 'MISSING'), "\n", ($p['tz_id'] ?? 'MISSING'), "\n", ($p['zone_type'] ?? 'MISSING'), "\n";

$gmt = date_parse('2020-01-02 03:04:05 GMT');
echo ($gmt['tz_abbr'] ?? 'MISSING'), '|', ($gmt['zone_type'] ?? 'MISSING'), '|', ($gmt['zone'] ?? 'MISSING'), "\n";
