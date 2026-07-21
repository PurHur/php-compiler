<?php
foreach (['XSD_DURATION', 'XSD_GYEARMONTH', 'XSD_GYEAR', 'XSD_GMONTHDAY', 'XSD_GDAY', 'XSD_GMONTH', 'XSD_NOTATION'] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'UNDEF', "\n";
}
$v = new SoapVar('P1D', XSD_DURATION);
echo 'enc_type=', $v->enc_type, "\n";
