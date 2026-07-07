<?php

declare(strict_types=1);

$a = [1, 2];
$b = &$a[0];
debug_zval_dump($a, $b);
