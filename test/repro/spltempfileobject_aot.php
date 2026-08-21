<?php
/** Issue #33431 — SplTempFileObject thin AOT vs Zend (php://temp). */
$f = new SplTempFileObject();
$n = $f->fwrite("hello\nworld\n");
$f->rewind();
$a = $f->fgets();
$b = $f->fgets();
echo 'class=', get_class($f),
    ' isa=', ($f instanceof SplFileObject) ? '1' : '0',
    ' n=', var_export($n, true),
    ' a=', var_export($a, true),
    ' b=', var_export($b, true),
    "\n";
