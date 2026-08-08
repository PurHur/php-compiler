<?php
// Repro for #28930 / re-#7261 — SortDirection phantom absent.

echo 'SortDirection enum: ', enum_exists('SortDirection', false) ? 'yes' : 'no', "\n";
if (enum_exists('SortDirection', false)) {
    fwrite(STDERR, "FAIL: SortDirection phantom still registered\n");
    exit(1);
}
echo "absent_ok\n";
