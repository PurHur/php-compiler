<?php
// Issue #30811 — convert_uuencode/uudecode thin AOT must match Zend (no segfault).
echo substr(convert_uuencode('test'), 0, 12), "\n";
echo convert_uudecode(convert_uuencode('test')), "\n";
echo convert_uudecode(convert_uuencode('cat')), "\n";
