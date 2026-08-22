<?php
// Issue #33896 — thin AOT range() must verify and match Zend (int/char/float).
echo implode(',', range(1, 5)), "\n";
echo implode(',', range('a', 'c')), "\n";
echo implode(',', range(1.0, 3.0, 0.5)), "\n";
