<?php
// #34474 — AOT after Type::initialize Ini/IncludePath/Session/Define/RewriteVars lazy-link
echo ini_get('precision') !== false ? "ini_ok\n" : "ini_no\n";
$ip = get_include_path();
echo is_string($ip) && $ip !== '' ? "ip_ok\n" : "ip_no\n";
define('PHPC_34474', 1);
echo defined('PHPC_34474') ? "def_ok\n" : "def_no\n";
echo session_status() === PHP_SESSION_NONE ? "ss_ok\n" : "ss_no\n";
output_add_rewrite_var('k', 'v');
output_reset_rewrite_vars();
echo "rw_ok\n";
