--TEST--
Language: abstract enum rejected — Zend parse error (#26519, re-#3737, Zend/zend_language_parser.y)
--FILE--
<?php
abstract enum E { case A; }
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AParse error%Asyntax error, unexpected token "enum", expecting "abstract" or "final" or "readonly" or "class"%A
