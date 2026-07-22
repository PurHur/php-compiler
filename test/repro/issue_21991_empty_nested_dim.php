<?php
// Issue #21991: empty($a['x']['y']) on missing nested dims — Zend true silent.
$a = [];
var_export(empty($a['x']['y']));
echo "\n";
var_export(isset($a['x']['y']));
echo "\n";
