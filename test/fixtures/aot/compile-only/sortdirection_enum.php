<?php

declare(strict_types=1);

// Compile-only (#7261): SortDirection pure enum must compile through AOT.
echo enum_exists('SortDirection', false) ? "yes\n" : "no\n";
echo SortDirection::Ascending->name, "\n";
echo SortDirection::Descending->name, "\n";
