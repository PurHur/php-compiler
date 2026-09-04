<?php

declare(strict_types=1);

/**
 * abs() AOT via llvm.fabs.f64 / i64 select must match Zend (#36386).
 *
 * @differential-repeat: 3
 */
echo abs(-2.5), '|', abs(2.5), '|', abs(-7), '|', abs(0), '|', abs(-0.0), "\n";
echo abs(PHP_INT_MIN + 1), '|', gettype(abs(PHP_INT_MIN)), "\n";
