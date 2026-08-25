<?php
/** Repro #34783 — file-scope const holding enum case. */
enum E: int { case A = 1; }
const X = E::A;
echo X->value, PHP_EOL;
