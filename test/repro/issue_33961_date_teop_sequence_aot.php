<?php
// Combined free date() T/e/O/P/r in one function — four unique NestedJIT token
// helpers SIGSEGV after O (#33961 / re-#33943 after #33958).
$ts = strtotime('2024-01-15 12:00:00');
echo date('T', $ts), "\n";
echo date('e', $ts), "\n";
echo date('O', $ts), "\n";
echo date('P', $ts), "\n";
echo date('r', $ts), "\n";
echo gmdate('T', $ts), "\n";
echo date(DATE_RFC2822, $ts), "\n";
date_default_timezone_set('Europe/Berlin');
echo date('T', 1721037600), "\n";
echo date('P', 1721037600), "\n";
