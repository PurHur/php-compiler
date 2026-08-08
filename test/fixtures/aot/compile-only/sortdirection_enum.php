<?php
declare(strict_types=1);

// Compile-only (#28930 / re-#7261): SortDirection phantom must stay absent through AOT.
echo enum_exists('SortDirection', false) ? "yes\n" : "no\n";
