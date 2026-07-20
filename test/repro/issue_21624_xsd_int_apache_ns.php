<?php
foreach ([
    'XSD_NONPOSITIVEINTEGER', 'XSD_NEGATIVEINTEGER', 'XSD_NONNEGATIVEINTEGER',
    'XSD_UNSIGNEDLONG', 'XSD_UNSIGNEDINT', 'XSD_UNSIGNEDSHORT', 'XSD_UNSIGNEDBYTE',
    'XSD_POSITIVEINTEGER', 'APACHE_MAP', 'XSD_1999_TIMEINSTANT',
] as $c) {
    echo $c, '=', defined($c) ? var_export(constant($c), true) : 'UNDEF', "\n";
}
echo 'XSD_NAMESPACE=', defined('XSD_NAMESPACE') ? var_export(XSD_NAMESPACE, true) : 'UNDEF', "\n";
echo 'XSD_1999_NAMESPACE=', defined('XSD_1999_NAMESPACE') ? var_export(XSD_1999_NAMESPACE, true) : 'UNDEF', "\n";
$v = new SoapVar(1, XSD_POSITIVEINTEGER);
echo 'enc_type=', $v->enc_type, "\n";
