<?php
/** Repro for #24808 / #22544 — json_validate phantom on default (Zend 8.2) profile. */
echo 'json_validate=', function_exists('json_validate') ? 'Y' : 'N', "\n";
