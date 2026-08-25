<?php
// #34558 — AOT: concat-assign inside && must keep prior CV (Zend/VM => g=AB)
$g = '';
($g .= 'A') && ($g .= 'B');
echo "g=$g\n";
