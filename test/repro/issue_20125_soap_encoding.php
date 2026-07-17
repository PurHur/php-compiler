<?php
echo 'SoapVar=', class_exists('SoapVar') ? 1 : 0, "\n";
echo 'SoapParam=', class_exists('SoapParam') ? 1 : 0, "\n";
echo 'SoapHeader=', class_exists('SoapHeader') ? 1 : 0, "\n";
echo 'XSD_STRING=', defined('XSD_STRING') ? (string) XSD_STRING : 'missing', "\n";

$v = new SoapVar('hi', XSD_STRING);
echo 'var_type=', $v->enc_type, "\n";
echo 'var_val=', $v->enc_value, "\n";

$p = new SoapParam(42, 'n');
echo 'param=', $p->param_name, ':', $p->param_data, "\n";

$h = new SoapHeader('urn:x', 'Auth', 'tok', true);
echo 'hdr=', $h->namespace, ':', $h->name, ':', $h->data, ':', $h->mustUnderstand ? 1 : 0, "\n";
