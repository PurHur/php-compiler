<?php
/** Maintainer repro for #9684 — enum case ->name/->value as direct call arguments. */
enum E: int { case A = 1; }
var_dump(E::A->name);
var_dump(E::A->value);

enum S { case A; }
var_dump(S::A->name);
