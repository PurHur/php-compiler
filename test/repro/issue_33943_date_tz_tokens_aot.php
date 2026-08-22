<?php
// #33943 — AOT date() timezone tokens must match Zend (no NestedJIT SIGSEGV).
$ts = strtotime('2024-01-15 12:00:00');
echo date('T', $ts), "\n";
echo date('e', $ts), "\n";
echo date('O', $ts), "\n";
echo date('P', $ts), "\n";
echo date('r', $ts), "\n";
echo gmdate('T', $ts), "\n";
echo date(DATE_RFC2822, $ts), "\n";
