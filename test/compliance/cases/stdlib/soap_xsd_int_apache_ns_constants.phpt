--TEST--
stdlib XSD integer/APACHE_MAP/namespace constants (#21624, ext/soap/php_encoding.h)
--FILE--
<?php
$expectInt = [
    'XSD_NONPOSITIVEINTEGER' => 132,
    'XSD_NEGATIVEINTEGER' => 133,
    'XSD_NONNEGATIVEINTEGER' => 138,
    'XSD_UNSIGNEDLONG' => 139,
    'XSD_UNSIGNEDINT' => 140,
    'XSD_UNSIGNEDSHORT' => 141,
    'XSD_UNSIGNEDBYTE' => 142,
    'XSD_POSITIVEINTEGER' => 143,
    'APACHE_MAP' => 200,
    'XSD_1999_TIMEINSTANT' => 401,
];
$ok = 1;
foreach ($expectInt as $name => $val) {
    if (!defined($name) || constant($name) !== $val) {
        $ok = 0;
        echo 'bad=', $name, ' got=', defined($name) ? var_export(constant($name), true) : 'MISSING', "\n";
    }
}
$ns = 'http://www.w3.org/2001/XMLSchema';
$ns1999 = 'http://www.w3.org/1999/XMLSchema';
if (!defined('XSD_NAMESPACE') || XSD_NAMESPACE !== $ns) {
    $ok = 0;
    echo 'bad=XSD_NAMESPACE got=', defined('XSD_NAMESPACE') ? var_export(XSD_NAMESPACE, true) : 'MISSING', "\n";
}
if (!defined('XSD_1999_NAMESPACE') || XSD_1999_NAMESPACE !== $ns1999) {
    $ok = 0;
    echo 'bad=XSD_1999_NAMESPACE got=', defined('XSD_1999_NAMESPACE') ? var_export(XSD_1999_NAMESPACE, true) : 'MISSING', "\n";
}
$v = new SoapVar(1, XSD_POSITIVEINTEGER);
if ($v->enc_type !== 143) {
    $ok = 0;
    echo 'bad=SoapVar enc_type=', $v->enc_type, "\n";
}
echo 'ok=', $ok, "\n";
echo 'pos=', XSD_POSITIVEINTEGER, "\n";
echo 'map=', APACHE_MAP, "\n";
echo 'ns=', XSD_NAMESPACE, "\n";
?>
--EXPECT--
ok=1
pos=143
map=200
ns=http://www.w3.org/2001/XMLSchema
