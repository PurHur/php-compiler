<?php
// #34905 — AOT parse_url with port must match Zend (was compiler Fatal on private Variable::$context)
var_export(parse_url('https://ex.com:443/a'));
echo "\n";
var_export(parse_url('http://u:p@h:8080/x?q=1#f'));
echo "\n";
echo parse_url('https://ex.com:443/a', PHP_URL_PORT), "\n";
