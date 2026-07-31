<?php
// #25816 — eval()-thrown Error must leave the try body (Zend zend_execute.c).
echo "A\n";
try {
    eval('class C implements NoSuchIface {}');
    echo "B_in_try\n";
} catch (Throwable $e) {
    echo "C_in_catch\n";
}
echo "D\n";
