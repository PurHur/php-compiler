<?php
/**
 * Repro for #28931 — ConnectionStatus / RequestMethod / ResponseCode phantoms absent.
 * php-src never ships these enums under PROFILE≥8.4.
 */
echo 'ConnectionStatus=', enum_exists('ConnectionStatus') ? 'Y' : 'N', "\n";
echo 'RequestMethod=', enum_exists('RequestMethod') ? 'Y' : 'N', "\n";
echo 'ResponseCode=', enum_exists('ResponseCode') ? 'Y' : 'N', "\n";
