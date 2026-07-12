--TEST--
stdlib mb_ucfirst()/mb_lcfirst() — UTF-8 first character case (#17609, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo mb_ucfirst('über'), "\n";
echo mb_lcfirst('Über'), "\n";
echo mb_ucfirst(''), "\n";
echo mb_ucfirst('ßtraße'), "\n";
--EXPECT--
Über
über

SStraße
