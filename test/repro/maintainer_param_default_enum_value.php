<?php
enum E: int { case A = 1; }

function f(int $n = E::A->value): int { return $n; }

var_dump(f());
