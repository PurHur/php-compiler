<?php
/** Repro for #24820 / #16292 — str_increment/str_decrement phantom on default (Zend 8.2) profile. */
echo 'str_increment=', function_exists('str_increment') ? 'Y' : 'N', "\n";
echo 'str_decrement=', function_exists('str_decrement') ? 'Y' : 'N', "\n";
