<?php
// #34724 — thin AOT PregAotFastPath must not Internal-error valid PCRE patterns.
var_dump(preg_match('/.*/', 'hello'));
var_dump(preg_last_error());
var_dump(preg_match('/^[0-9a-f]{32}$/', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
var_dump(preg_match('/x/', 'abc'));
$o = new stdClass;
$h = spl_object_hash($o);
echo strlen($h), '|', preg_match('/^[0-9a-f]{32}$/', $h), "\n";
