<?php

declare(strict_types=1);

/**
 * AOT: mb_output_handler() with runtime string arg (#20014 leftover).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_output_handler)
 */
mb_internal_encoding('UTF-8');
mb_http_output('ISO-8859-1');

$s = "caf\xC3\xA9";
// Literal 9 === PHP_OUTPUT_HANDLER_START|END (status may be runtime in OB callbacks; string was the AOT blocker).
echo 'rt=', bin2hex(mb_output_handler($s, 9)), "\n";

mb_http_output('pass');
echo 'pass=', bin2hex(mb_output_handler($s, 9)), "\n";
