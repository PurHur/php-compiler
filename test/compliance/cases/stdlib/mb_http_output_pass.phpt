--TEST--
stdlib mb_http_output('pass') — identity encoding alias (#14315, ext/mbstring/mbstring.c)
--FILE--
<?php
mb_http_output('UTF-8');
echo mb_http_output('pass') ? "set ok\n" : "set fail\n";
echo mb_http_output(), "\n";
mb_http_output('SJIS');
echo mb_http_output(), "\n";
mb_http_output('pass');
echo mb_http_output(), "\n";
mb_http_output('UTF-8');
--EXPECT--
set ok
pass
SJIS
pass
