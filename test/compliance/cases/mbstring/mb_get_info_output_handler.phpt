--TEST--
stdlib mb_get_info()/mb_output_handler() — state dump + HTTP output convert (#20014, ext/mbstring/mbstring.c)
--FILE--
<?php
echo function_exists('mb_get_info') ? "info_ok\n" : "info_missing\n";
echo function_exists('mb_output_handler') ? "handler_ok\n" : "handler_missing\n";
$all = mb_get_info();
echo $all['internal_encoding'], "\n";
echo $all['http_output'], "\n";
echo $all['language'], "\n";
echo $all['mail_charset'], "\n";
echo $all['mail_header_encoding'], "\n";
echo $all['strict_detection'], "\n";
echo $all['encoding_translation'], "\n";
var_export(mb_get_info('http_input'));
echo "\n";
var_export(mb_get_info('func_overload'));
echo "\n";
var_export(mb_get_info('no_such_type'));
echo "\n";
echo mb_get_info('http_output_conv_mimetypes'), "\n";

mb_internal_encoding('UTF-8');
mb_http_output('ISO-8859-1');
$flags = PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_END;
$converted = mb_output_handler("caf\xC3\xA9", $flags);
echo bin2hex($converted), "\n";
mb_http_output('pass');
$pass = mb_output_handler("caf\xC3\xA9", $flags);
echo bin2hex($pass), "\n";

// Callable as OB handler (CLI often skips mime-gated conversion; identity is OK).
mb_http_output('UTF-8');
ob_start('mb_output_handler');
echo "ok";
$buf = ob_get_clean();
echo $buf, "\n";
--EXPECT--
info_ok
handler_ok
UTF-8
UTF-8
neutral
UTF-8
BASE64
Off
Off
NULL
false
false
^(text/|application/xhtml\+xml)
636166e9
636166c3a9
ok
