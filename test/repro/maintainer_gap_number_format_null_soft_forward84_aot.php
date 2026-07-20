<?php
// #21429 — AOT soft-null number_format(null) on PROFILE=8.4 (no TypeError).
// Avoid set_error_handler / var_export — known AOT compile/runtime gaps.
error_reporting(E_ALL & ~E_DEPRECATED);
echo number_format(null), "\n";
echo "ALL_OK\n";
