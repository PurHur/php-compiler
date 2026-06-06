<?php
enum E: int { case A = 1; }
[$x] = [E::A];
var_export($x);
var_export($x === E::A);
