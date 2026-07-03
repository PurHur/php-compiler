<?php

declare(strict_types=1);

/**
 * Issue #9982 — session_set_cookie_params() / session_get_cookie_params() (ext/session/session.c).
 */
session_set_cookie_params(['lifetime' => 3600, 'path' => '/']);
$params = session_get_cookie_params();
var_export(function_exists('session_set_cookie_params'));
echo "\n";
var_export(function_exists('session_get_cookie_params'));
echo "\n";
var_export($params['lifetime']);
echo "\n";
var_export($params['path']);
echo "\n";
