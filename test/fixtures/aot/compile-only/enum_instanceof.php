<?php
// Compile-only regression for enum instanceof (#3550); native AOT link segfaults (MCJIT).
enum E: string
{
    case A = 'a';
}

echo (E::A instanceof E) ? "1\n" : "0\n";
echo (E::A instanceof UnitEnum) ? "1\n" : "0\n";
