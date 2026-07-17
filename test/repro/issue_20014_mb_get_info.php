<?php
/**
 * Repro #20014 — mb_get_info() / mb_output_handler() vs Zend.
 */
echo 'exists_info=', function_exists('mb_get_info') ? '1' : '0', "\n";
echo 'exists_handler=', function_exists('mb_output_handler') ? '1' : '0', "\n";
if (!function_exists('mb_get_info') || !function_exists('mb_output_handler')) {
    exit(1);
}
$all = mb_get_info();
echo 'internal=', $all['internal_encoding'] ?? '?', "\n";
echo 'http_output=', mb_get_info('http_output'), "\n";
echo 'language=', mb_get_info('language'), "\n";
echo 'mail_charset=', mb_get_info('mail_charset'), "\n";
echo 'unknown=', var_export(mb_get_info('nope'), true), "\n";
echo 'http_input=', var_export(mb_get_info('http_input'), true), "\n";

mb_internal_encoding('UTF-8');
mb_http_output('ISO-8859-1');
$flags = PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_END;
$out = mb_output_handler("caf\xC3\xA9", $flags);
echo 'converted=', bin2hex($out), "\n";

mb_http_output('pass');
$pass = mb_output_handler("caf\xC3\xA9", $flags);
echo 'pass=', bin2hex($pass), "\n";
