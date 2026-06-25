<?php
// Repro for #11473 — Zend fatals; VM must not print "compiled".
function acceptsNever(never $value): void {}

echo "compiled\n";
