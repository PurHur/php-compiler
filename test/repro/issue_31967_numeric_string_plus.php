<?php
/** Repro for #31967 — numeric-string arithmetic and NAN spaceship in AOT. */
var_dump("5" + 5);
var_dump(NAN <=> 1.0);
