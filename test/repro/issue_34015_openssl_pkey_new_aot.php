<?php
$k = openssl_pkey_new();
echo is_object($k) ? get_class($k) : var_export($k, true);
echo "\n";
$k2 = openssl_pkey_new(null);
echo is_object($k2) ? get_class($k2) : var_export($k2, true);
echo "\n";
