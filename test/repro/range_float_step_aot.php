<?php
/**
 * Repro #27158 — AOT range() with float step/bounds must match Zend/VM/JIT.
 */
echo implode(',', range(0, 1, 0.5)), "\n";
echo implode(',', range(1.5, 3.5, 0.5)), "\n";
echo implode(',', range(3.5, 1.5, -0.5)), "\n";
