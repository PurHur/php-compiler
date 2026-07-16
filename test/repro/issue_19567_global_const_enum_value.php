<?php
enum E: int { case A = 1; }
const X = E::A->value;
echo X, "\n";
