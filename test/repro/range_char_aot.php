<?php
/**
 * Repro #27563 — AOT char range("a","c") must match Zend/VM/JIT.
 */
echo implode(',', range('a', 'c'));
