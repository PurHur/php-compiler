--TEST--
Language: final global typed constants rejected — Zend parse error (#10324, #15185, #16674, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

final const string APP_NAME = 'alpha';

echo APP_NAME, "\n";
--EXPECTF--
Fatal error: syntax error, unexpected token "const", expecting "abstract" or "final" or "readonly" or "class" in %s on line %d
