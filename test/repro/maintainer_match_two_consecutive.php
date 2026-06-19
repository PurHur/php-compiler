<?php
enum E: int { case A = 1; }
var_dump(match (1) { E::A => "a", default => "d" });
var_dump(match (E::A) { 1 => "i", default => "d" });
