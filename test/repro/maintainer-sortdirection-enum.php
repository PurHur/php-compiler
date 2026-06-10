<?php
// Repro for #7261 — SortDirection enum (ext/standard/basic_functions.stub.php).

echo 'SortDirection enum: ', enum_exists('SortDirection', false) ? 'yes' : 'no', "\n";
if (!enum_exists('SortDirection', false)) {
    fwrite(STDERR, "FAIL: SortDirection enum missing\n");
    exit(1);
}
echo 'unit enum: ', unitenum_exists('SortDirection') ? 'yes' : 'no', "\n";
echo 'Ascending: ', SortDirection::Ascending->name, "\n";
echo 'Descending: ', SortDirection::Descending->name, "\n";
