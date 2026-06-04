<?php
enum E: int { case A = 1; }
var_dump(is_scalar(E::A));
var_dump(is_scalar(E::cases()[0]));
