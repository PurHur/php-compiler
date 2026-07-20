<?php
foreach ([
    'XSD_NORMALIZEDSTRING', 'XSD_TOKEN', 'XSD_LANGUAGE', 'XSD_NMTOKEN', 'XSD_NAME', 'XSD_NCNAME',
    'XSD_ID', 'XSD_IDREF', 'XSD_IDREFS', 'XSD_ENTITY', 'XSD_ENTITIES', 'XSD_NMTOKENS',
] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'UNDEF', "\n";
}
$v = new SoapVar('tok', XSD_TOKEN);
echo 'enc_type=', $v->enc_type, "\n";
