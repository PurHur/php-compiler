<?php

declare(strict_types=1);

$s256 = str_repeat('a', 256);
$p = 0.0;
$sim = similar_text($s256, $s256, $p);
echo "sim={$sim} percent={$p}\n";
