<?php
// AOT compile-only (#6133): setlocale()/localeconv() JIT lowering via JitLocale.
setlocale(LC_ALL, 'C');
echo setlocale(LC_ALL, null), "\n";
$lc = localeconv();
echo $lc['decimal_point'], "\n";
