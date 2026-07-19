--TEST--
datefmt_parse/localtime/get_error_* procedural aliases (#20803)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlDateFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
foreach ([
    'datefmt_parse',
    'datefmt_localtime',
    'datefmt_get_error_code',
    'datefmt_get_error_message',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
$f = IntlDateFormatter::create('en_US', -1, -1, 'UTC', IntlDateFormatter::GREGORIAN, 'yyyy-MM-dd');
$oop = $f->parse('2024-06-15');
$proc = datefmt_parse($f, '2024-06-15');
echo 'match_parse=', (int) ($oop === $proc), "\n";
echo 'parse=', $proc, "\n";

$oopLt = $f->localtime('2024-06-15');
$procLt = datefmt_localtime($f, '2024-06-15');
echo 'match_localtime=', (int) ($oopLt === $procLt), "\n";
echo 'y=', $procLt['tm_year'] + 1900, ' m=', $procLt['tm_mon'] + 1, ' d=', $procLt['tm_mday'], "\n";

$pos = 2;
echo 'from_offset=', datefmt_parse($f, 'xx2024-06-15', $pos), "\n";

echo 'bad=', var_export(datefmt_parse($f, 'nope'), true), "\n";
echo 'code=', datefmt_get_error_code($f), "\n";
echo 'msg=', datefmt_get_error_message($f), "\n";
?>
--EXPECT--
datefmt_parse=1
datefmt_localtime=1
datefmt_get_error_code=1
datefmt_get_error_message=1
match_parse=1
parse=1718409600
match_localtime=1
y=2024 m=6 d=15
from_offset=1718409600
bad=false
code=9
msg=Date parsing failed: U_PARSE_ERROR
