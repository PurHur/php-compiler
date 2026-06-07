<?php
// Issue #6334 — uncaught builtin TypeError must fatal at user site, not ExceptionSupport.php
class C {}
$o = new C();
// Zend: TypeError at this line (array_key_exists Z_PARAM_ARRAY)
array_key_exists('k', $o);
