<?php
enum E: int { case A = 1; }
var_dump(match (1) { E::A => "a", default => "d" });
