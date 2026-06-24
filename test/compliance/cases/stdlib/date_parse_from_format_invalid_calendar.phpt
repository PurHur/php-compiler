--TEST--
date_parse_from_format() invalid calendar — warning array, preserved fields (#11226, ext/standard/parsedate.c)
--FILE--
<?php
$r = date_parse_from_format('Y-m-d', '2020-13-40');
echo $r['year'], '-', $r['month'], '-', $r['day'], "\n";
echo $r['warning_count'], "\n";
echo $r['warnings'][10], "\n";
echo $r['error_count'], "\n";

$ok = date_parse_from_format('Y-m-d', '2024-01-01');
echo $ok['year'], '-', $ok['month'], '-', $ok['day'], "\n";
echo $ok['warning_count'], "\n";
--EXPECT--
2020-13-40
1
The parsed date was invalid
0
2024-1-1
0
