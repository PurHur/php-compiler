<?php
// FAILS ON AOT — #24220. print_r(null) segfaults; the trailing echo never runs.
// Zend prints nothing for print_r(null), so the whole expected output is "|done".
print_r(null);
echo "|done\n";
