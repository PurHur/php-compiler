<?php
/** Repro #34783 — string-backed file-scope const. */
enum S: string { case A = "hi"; }
const Z = S::A;
echo Z->value, PHP_EOL;
