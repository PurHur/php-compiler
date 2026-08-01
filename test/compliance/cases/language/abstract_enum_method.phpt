--TEST--
Language: abstract enum with abstract method rejected — Zend parse error (#26519, re-#6887)
--FILE--
<?php
abstract enum E: int {
    case A = 1;
    abstract public function label(): string;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AParse error%Asyntax error, unexpected token "enum", expecting "abstract" or "final" or "readonly" or "class"%A
