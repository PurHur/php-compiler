<?php

declare(strict_types=1);

/**
 * Issue #18832: AOT user-script must read QUERY_STRING into $_REQUEST for inline echo.
 *
 * Local assign from $_REQUEST then htmlspecialchars($local) is a separate JIT gap;
 * this repro guards the refresh + inline coalesce echo path used by 001-SimpleWeb.
 */
header('Content-Type: text/plain; charset=UTF-8');
echo 'Hello ', ($_REQUEST['name'] ?? ''), "\n";
