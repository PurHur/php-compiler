<?php
// Compile-only (#12017): array_multisort() inline array literal must compile for AOT.
array_multisort([1, 2], SORT_DESC, SORT_NUMERIC);
echo "ok\n";
