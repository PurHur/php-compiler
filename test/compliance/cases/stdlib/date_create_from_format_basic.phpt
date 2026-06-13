--TEST--
stdlib date_create_from_format/date_parse — procedural format parsing (#6172)
--FILE--
<?php
foreach ([
    'date_create_from_format',
    'date_create_immutable_from_format',
    'date_parse',
    'date_parse_from_format',
] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";

$r = date_create_from_format('Y-m-d', '2024-06-05');
var_export($r instanceof DateTime);
echo "\n";
echo $r->format('Y-m-d'), "\n";

$p = date_parse('2024-01-15 12:00:00');
echo isset($p['year']) ? (string) $p['year'] : 'fail';
echo "\n";

$p2 = date_parse_from_format('Y-m-d', '2024-06-05');
echo (string) $p2['year'], "\n";

$ri = date_create_immutable_from_format('Y-m-d H:i:s', '2024-01-15 12:00:00', new DateTimeZone('UTC'));
echo $ri->format('Y-m-d H:i:s'), "\n";

var_export(date_create_from_format('Y-m-d', 'bad'));
echo "\n";
?>
--EXPECT--
PHP Warning:  date_create_from_format(): Failed to parse time string (bad)
1111
true
2024-06-05
2024
2024
2024-01-15 12:00:00
false
