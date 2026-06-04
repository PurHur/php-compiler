--TEST--
Language: const/define/class const with enum case RHS materialize singleton (#5738)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'x';
}

const FILE_C = E::A;

class C {
    public const CLASS_C = E::A;
}

define('DEFINE_C', E::A);

echo var_export(FILE_C, true), "\n";
echo var_export(C::CLASS_C, true), "\n";
echo var_export(DEFINE_C, true), "\n";
echo (FILE_C === E::A && C::CLASS_C === E::A && DEFINE_C === E::A) ? "same\n" : "diff\n";
--EXPECT--
\E::A
\E::A
\E::A
same
