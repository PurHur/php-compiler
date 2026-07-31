<?php
/** Repro for #25874 — socket_atmark phantom on default (Zend 8.2) profile. */
echo 'sockets=', extension_loaded('sockets') ? 'Y' : 'N', "\n";
echo 'socket_atmark=', function_exists('socket_atmark') ? 'Y' : 'N', "\n";
echo 'json_validate=', function_exists('json_validate') ? 'Y' : 'N', "\n";
