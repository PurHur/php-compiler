<?php

/**
 * #24522 — WeakMap var_dump / var_export must match Zend/zend_weakrefs.c
 * (key/value debug pairs; opaque empty __set_state).
 */
$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = ['a' => 1];
var_dump($wm);
echo 'EXPORT=';
var_export($wm);
echo "\n";
