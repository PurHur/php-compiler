<?php
/** Repro #22001 — "${var}" emits E_DEPRECATED like Zend 8.2+. */
$foo = 'bar';
echo "${foo}\n";
