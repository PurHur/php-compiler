<?php
/** Repro for #31967 — enum case as a class-constant value. */
enum E: string {
    case X = 'h';
}
class C {
    public const K = E::X;
}
echo C::K->value;
