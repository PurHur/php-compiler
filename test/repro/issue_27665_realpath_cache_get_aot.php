<?php

declare(strict_types=1);

// #27665 — AOT realpath_cache_get() must return an array (empty snapshot OK).
// Avoid is_array(?):(concat) ternary — thin AOT segfaults that pattern for any array.
$g = realpath_cache_get();
echo is_array($g) ? 'arr' : 'no';
echo '|';
echo count($g);
echo "\n";
