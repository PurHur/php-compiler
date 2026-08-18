<?php
/**
 * #32321 — var_dump(INF/NAN) must use zend_gcvt tokens (ext/standard/var.c php_var_dump).
 * Thin AOT used libc snprintf %.14g ("inf"/"nan").
 */
var_dump(INF);
var_dump(NAN);
var_dump(1.5);
