<?php
// AOT compile-only (#6133): setlocale()/localeconv() JIT lowering via JitLocale.
setlocale(LC_ALL, 'C');
echo setlocale(LC_ALL, null), "\n";
$query0 = setlocale(LC_ALL, '0');
echo is_string($query0) ? '1' : '0', "\n";
$lc = localeconv();
echo $lc['decimal_point'], "\n";
