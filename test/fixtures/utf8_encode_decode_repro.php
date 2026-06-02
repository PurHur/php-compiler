<?php
// Issue #3632 repro — ISO-8859-1 ↔ UTF-8 round-trip.
$latin1 = "\xE9";
echo bin2hex(utf8_encode($latin1)), "\n";
echo bin2hex(utf8_decode(utf8_encode($latin1))), "\n";
echo bin2hex(utf8_decode("\xC3\x28")), "\n";
