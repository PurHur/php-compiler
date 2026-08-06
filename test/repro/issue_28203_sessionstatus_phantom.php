<?php

declare(strict_types=1);

/**
 * Repro for #28203 — SessionStatus phantom absent under PROFILE≥8.4.
 */
echo 'enum_exists=', enum_exists('SessionStatus') ? 'true' : 'false', "\n";
echo 'session_status=', session_status(), "\n";
echo 'none_ok=', session_status() === PHP_SESSION_NONE ? '1' : '0', "\n";
