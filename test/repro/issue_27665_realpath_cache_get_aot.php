<?php

declare(strict_types=1);

// #27665 — AOT realpath_cache_get() must return an array (empty snapshot OK).
$g = realpath_cache_get();
echo is_array($g) ? ('arr|'.count($g)) : 'no';
echo "\n";
