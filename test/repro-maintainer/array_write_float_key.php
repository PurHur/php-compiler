<?php
/** Issue #5118 — float write keys coerce like php-src zend_hash_index_update. */
$a = [];
$a[1.5] = 'x';
var_export($a);
