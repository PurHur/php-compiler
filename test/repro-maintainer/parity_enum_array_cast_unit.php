<?php
/** Maintainer repro for #5536 — (array) on unit enum case must use name key. */
enum E { case A; }
var_export((array) E::A);
