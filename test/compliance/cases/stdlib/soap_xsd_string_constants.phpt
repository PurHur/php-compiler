--TEST--
stdlib XSD string-derived constants (#21623, ext/soap/php_encoding.h)
--FILE--
<?php
$expect = [
    'XSD_NORMALIZEDSTRING' => 120,
    'XSD_TOKEN' => 121,
    'XSD_LANGUAGE' => 122,
    'XSD_NMTOKEN' => 123,
    'XSD_NAME' => 124,
    'XSD_NCNAME' => 125,
    'XSD_ID' => 126,
    'XSD_IDREF' => 127,
    'XSD_IDREFS' => 128,
    'XSD_ENTITY' => 129,
    'XSD_ENTITIES' => 130,
    'XSD_NMTOKENS' => 144,
];
$ok = 1;
foreach ($expect as $name => $val) {
    if (!defined($name) || constant($name) !== $val) {
        $ok = 0;
        echo 'bad=', $name, ' got=', defined($name) ? (string) constant($name) : 'MISSING', "\n";
    }
}
$v = new SoapVar('tok', XSD_TOKEN);
if ($v->enc_type !== 121) {
    $ok = 0;
    echo 'bad=SoapVar enc_type=', $v->enc_type, "\n";
}
echo 'ok=', $ok, "\n";
echo 'token=', XSD_TOKEN, "\n";
echo 'nmtokens=', XSD_NMTOKENS, "\n";
?>
--EXPECT--
ok=1
token=121
nmtokens=144
