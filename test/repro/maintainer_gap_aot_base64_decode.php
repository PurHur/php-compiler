<?php

/**
 * Repro for #26890 — AOT base64_decode must match Zend (no segfault).
 * Use a runtime string so constant-fold cannot hide a broken helper TU.
 */
$s = $argv[1] ?? 'aGk=';
echo base64_decode($s), "\n";
echo base64_encode('hi'), '|', base64_decode(base64_encode('hi')), "\n";
