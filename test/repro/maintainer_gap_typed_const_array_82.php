<?php

declare(strict_types=1);

/**
 * Maintainer gap repro: top-level typed array const on Zend 8.2 reference profile (#16651).
 */
const array A = [];

echo "fail: executed\n";
