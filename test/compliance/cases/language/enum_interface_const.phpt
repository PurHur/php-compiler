--TEST--
Language: enum implements interface — interface class constants resolve (zend_enum.c, #5725)
--FILE--
<?php
interface I {
    public const X = 1;
}

enum E implements I {
    case A;
}

var_export(E::X);
echo "\n";

interface J {
    public const Y = 'y';
}

enum F: string implements J {
    case B = 'b';
}

var_export(F::Y);
echo "\n";
--EXPECT--
1
'y'
