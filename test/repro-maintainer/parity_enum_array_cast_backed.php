<?php
/** Maintainer repro for #5536 — backed enum (array) cast must keep name + value keys. */
enum E: int { case A = 1; }
var_export((array) E::A);
