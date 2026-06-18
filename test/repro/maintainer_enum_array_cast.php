<?php
/** Maintainer repro for #9629 — (array) cast on enum cases (Zend/zend_enum.c). */
enum B: string { case A = '1'; }
enum U { case X; }

var_export((array) B::A);
echo "\n";
var_export((array) U::X);
echo "\n";
