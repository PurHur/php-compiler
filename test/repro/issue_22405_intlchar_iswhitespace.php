<?php
// AOT/VM repro for #22405 — IntlChar::isWhitespace
echo 'method=', method_exists('IntlChar', 'isWhitespace') ? '1' : '0', "\n";
echo 'space=', IntlChar::isWhitespace(0x20) ? '1' : '0', "\n";
echo 'A=', IntlChar::isWhitespace(0x41) ? '1' : '0', "\n";
