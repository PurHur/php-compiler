<?php

declare(strict_types=1);

/**
 * Uncaught throw fixture for HTTP 500 serve smoke (#2200, #152).
 *
 *   ./phpc serve 127.0.0.1:8080 examples/007-ThrowsWeb
 *   curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/uncaught.php
 */
class UncaughtProbe
{
}

header('Content-Type: text/html; charset=UTF-8');
throw new UncaughtProbe();
