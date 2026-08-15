<?php
/** Repro #31309 — iconv() null encodings soft-null E_DEPRECATED on default profile. */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo var_export(iconv(null, 'UTF-8', 'a'), true), "\n";
echo var_export(iconv('UTF-8', null, 'a'), true), "\n";
echo var_export(iconv('UTF-8', 'UTF-8', 'a'), true), "\n";
