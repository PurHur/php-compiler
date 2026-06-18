<?php
declare(strict_types=1);

// Compile-only smoke: inline literal by-ref Error lowering (#9745).
array_shift([1, 2]);
array_pop([1, 2]);
array_unshift([1, 2], 0);
