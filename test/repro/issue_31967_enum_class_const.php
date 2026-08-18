<?php
/** Repro for #31967 — enum case as a class-constant value. */
enum E: string { case H = 'h'; }
class C { const X = E::H; }
echo C::X->value;
