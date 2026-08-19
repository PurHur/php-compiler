--TEST--
Language: array vs null/bool < > <=> matches zend_compare zend_is_true (#32520 leftover of #32503)
--FILE--
<?php
echo ([] > null) ? "en\n" : "nen\n";
echo ([] <=> null), "\n";
echo ([] > false) ? "ef\n" : "nef\n";
echo ([] <=> false), "\n";
echo ([1] > true) ? "nt\n" : "nnt\n";
echo ([1] <=> true), "\n";
echo ([1] > null) ? "nn\n" : "nnn\n";
echo ([1] <=> null), "\n";
echo ([] > 0) ? "ez\n" : "nez\n";
echo ([1] > 0) ? "nz\n" : "nnz\n";
echo (null > []) ? "rn\n" : "nrn\n";
echo ([] >= null) ? "ge\n" : "nge\n";
echo ([] <= false) ? "le\n" : "nle\n";
echo (true > [1]) ? "rt\n" : "nrt\n";
?>
--EXPECT--
nen
0
nef
0
nnt
0
nn
1
ez
nz
nrn
ge
le
nrt
