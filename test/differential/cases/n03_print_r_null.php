<?php
// #24220 / #24259 — thin standalone AOT scalar bridge (null → "").
// Zend prints nothing for print_r(null), so the whole expected output is "|done".
print_r(null);
echo "|done\n";
