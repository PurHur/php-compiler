<?php
// Repro #22620 — pg_set_error_context_visibility + PGSQL_SHOW_CONTEXT_* are PHP 8.3+
declare(strict_types=1);

echo 'fn=', function_exists('pg_set_error_context_visibility') ? '1' : '0', "\n";
foreach (['PGSQL_SHOW_CONTEXT_NEVER', 'PGSQL_SHOW_CONTEXT_ERRORS', 'PGSQL_SHOW_CONTEXT_ALWAYS'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
