<?php
/**
 * #4297 — substr_compare(..., $len) with runtime null $length must match Zend (string.c).
 */
$len = null;
var_dump(substr_compare('abcde', 'bc', 1, $len));
$offset = 1;
var_dump(substr_compare('abcde', 'bc', $offset, $len));
