<?php
// Repro #20938 — IntlChar isID*/isJava*/isISOControl/getFC_NFKC_Closure (php-src-strict).
$need = [
    'isIDStart', 'isIDPart', 'isIDIgnorable', 'isISOControl',
    'isJavaIDStart', 'isJavaIDPart', 'isJavaSpaceChar', 'getFC_NFKC_Closure',
];
foreach ($need as $m) {
    echo $m, '=', method_exists('IntlChar', $m) ? 'yes' : 'no', "\n";
}
echo 'isIDStart(A)=', IntlChar::isIDStart('A') ? 'true' : 'false', "\n";
echo 'isIDPart(1)=', IntlChar::isIDPart('1') ? 'true' : 'false', "\n";
echo 'isIDIgnorable(nul)=', IntlChar::isIDIgnorable("\x00") ? 'true' : 'false', "\n";
echo 'isISOControl(lf)=', IntlChar::isISOControl("\n") ? 'true' : 'false', "\n";
echo 'isJavaIDStart(A)=', IntlChar::isJavaIDStart('A') ? 'true' : 'false', "\n";
echo 'isJavaIDPart($)=', IntlChar::isJavaIDPart('$') ? 'true' : 'false', "\n";
echo 'isJavaSpaceChar(sp)=', IntlChar::isJavaSpaceChar(' ') ? 'true' : 'false', "\n";
$fc = IntlChar::getFC_NFKC_Closure('a');
echo 'getFC_NFKC_Closure(a)=', \is_string($fc) ? 'string:'.\strlen($fc) : \gettype($fc), "\n";
