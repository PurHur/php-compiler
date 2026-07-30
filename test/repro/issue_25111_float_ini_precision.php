<?php
declare(strict_types=1);

/**
 * #25111 — json_encode / var_export / print_r must honor serialize_precision / precision.
 */
ini_set('serialize_precision', '10');
ini_set('precision', '10');
echo 'json=', json_encode(1 / 3), "\n";
echo 've=', var_export(1 / 3, true), "\n";
echo 'pr=', print_r(1 / 3, true), "\n";
echo 'ser=', serialize(1 / 3), "\n";
echo 'str=', (string) (1 / 3), "\n";
