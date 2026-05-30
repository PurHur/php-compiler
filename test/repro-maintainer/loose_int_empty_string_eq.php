<?php
// Maintainer repro: #3686 — int↔empty string loose == (Zend zend_operators.c)
var_dump(0 == '');
var_dump('' == 0);
var_dump(0 == '0');
var_dump(0 == false);
