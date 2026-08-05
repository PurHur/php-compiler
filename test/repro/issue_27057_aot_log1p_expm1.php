<?php
// Issue #27057 — AOT log1p/expm1 must match Zend (not silently return 0).
echo round(log1p(1), 5), PHP_EOL;
echo round(expm1(1), 5), PHP_EOL;
