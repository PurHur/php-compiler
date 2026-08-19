<?php

declare(strict_types=1);

// AOT: filter_var(..., FILTER_VALIDATE_IP) with literal args (dispatchConstFilter path).
echo var_export(filter_var('127.0.0.1', FILTER_VALIDATE_IP), true), "\n";
echo var_export(filter_var('::1', FILTER_VALIDATE_IP), true), "\n";
echo var_export(filter_var('999.0.0.1', FILTER_VALIDATE_IP), true), "\n";
