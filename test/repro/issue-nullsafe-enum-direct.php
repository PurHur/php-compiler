<?php

enum E: int { case A = 1; }

var_export(E::A->name);
echo "\n";
var_export(E::A->value);
echo "\n";

