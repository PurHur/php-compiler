<?php
/**
 * Repro #26639 — bare `resource` type emits Zend confusable-type Warning.
 */
function f(resource $x) {}
echo "ok\n";
