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
$unknown = mb_get_info('nope');
echo 'unknown=', ($unknown === false ? 'false' : 'other'), "\n";
$httpIn = mb_get_info('http_input');
echo 'http_input=', (null === $httpIn ? 'NULL' : 'other'), "\n";

mb_internal_encoding('UTF-8');
mb_http_output('ISO-8859-1');
// Literal 9 === PHP_OUTPUT_HANDLER_START|END for thin AOT fold (#20014).
$out = mb_output_handler("caf\xC3\xA9", 9);
echo 'converted=', bin2hex($out), "\n";

mb_http_output('pass');
$pass = mb_output_handler("caf\xC3\xA9", 9);
echo 'pass=', bin2hex($pass), "\n";
