<?php

declare(strict_types=1);

// AOT: filter_var(..., FILTER_VALIDATE_IP) with dynamic string (#32571).
$ip = '127.0.0.1';
echo var_export(filter_var($ip, FILTER_VALIDATE_IP), true), "\n";
$ip6 = '::1';
echo var_export(filter_var($ip6, FILTER_VALIDATE_IP), true), "\n";
echo var_export(filter_var('999.0.0.1', FILTER_VALIDATE_IP), true), "\n";
