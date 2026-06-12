<?php
var_dump(function_exists('dns_get_record'));
$r = @dns_get_record('php.net', DNS_A);
var_dump(is_array($r) || $r === false);
