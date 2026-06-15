<?php
declare(strict_types=1);
// Compile-only (#6009): iconv() must lower runtime charset conversion for AOT.
$bytes = "\xE9";
echo iconv('ISO-8859-1', 'UTF-8', $bytes), "\n";
