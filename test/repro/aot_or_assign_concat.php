<?php
// #34558 peer — AOT: concat-assign inside || short-circuit (Zend/VM => g=A)
$g = '';
($g .= 'A') || ($g .= 'B');
echo "g=$g\n";
