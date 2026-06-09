<?php
echo function_exists('checkdnsrr') ? "fn\n" : "no-fn\n";
echo function_exists('dns_check_record') ? "alias-fn\n" : "no-alias\n";
var_export(checkdnsrr('example.com', 'MX'));
echo "\n";
