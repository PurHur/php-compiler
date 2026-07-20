--TEST--
stdlib XSD date/g*/NOTATION constants (#21622, ext/soap/php_encoding.h)
--FILE--
<?php
$expect = [
    'XSD_DURATION' => 106,
    'XSD_GYEARMONTH' => 110,
    'XSD_GYEAR' => 111,
    'XSD_GMONTHDAY' => 112,
    'XSD_GDAY' => 113,
    'XSD_GMONTH' => 114,
    'XSD_NOTATION' => 119,
];
$ok = 1;
foreach ($expect as $name => $val) {
    if (!defined($name) || constant($name) !== $val) {
        $ok = 0;
        echo 'bad=', $name, ' got=', defined($name) ? (string) constant($name) : 'MISSING', "\n";
    }
}
$v = new SoapVar('P1D', XSD_DURATION);
if ($v->enc_type !== 106) {
    $ok = 0;
    echo 'bad=SoapVar enc_type=', $v->enc_type, "\n";
}
echo 'ok=', $ok, "\n";
echo 'duration=', XSD_DURATION, "\n";
echo 'gyear=', XSD_GYEAR, "\n";
?>
--EXPECT--
ok=1
duration=106
gyear=111
