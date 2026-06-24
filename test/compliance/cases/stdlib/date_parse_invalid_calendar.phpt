--TEST--
stdlib date_parse() invalid calendar overflow + garbage input (#11225, ext/standard/parsedate.c)
--FILE--
<?php
declare(strict_types=1);
$r = date_parse('2020-13-40');
echo $r['year'], '-', $r['month'], '-', $r['day'], "\n";
echo $r['error_count'], "\n";
echo $r['errors'][6], "\n";

$r2 = date_parse('2024-02-30');
echo $r2['month'], '-', $r2['day'], "\n";
echo $r2['warning_count'], "\n";
echo $r2['warnings'][11], "\n";

$r3 = date_parse('not-a-date');
echo var_export($r3['year'], true), "\n";
echo $r3['error_count'], "\n";
echo $r3['warning_count'], "\n";

$ok = date_parse('2024-01-01');
echo $ok['year'], '-', $ok['month'], '-', $ok['day'], "\n";
echo $ok['error_count'], "\n";
?>
--EXPECT--
2020-1-1
1
Unexpected character
2-30
1
The parsed date was invalid
false
4
1
2024-1-1
0
