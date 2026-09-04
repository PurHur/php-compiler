<?php
// Differential: floor/ceil vs Zend via llvm.floor.f64 / llvm.ceil.f64 (#36386)
echo floor(1.7), "\n";
echo floor(-1.2), "\n";
echo ceil(1.2), "\n";
echo ceil(-1.7), "\n";
echo floor(0.0), "\n";
echo ceil(-0.0), "\n";
$x = 2.5;
echo floor($x) + ceil(-$x), "\n";
