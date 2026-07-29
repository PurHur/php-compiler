<?php
declare(strict_types=1);
/**
 * #24994 / #24627 — ext/pgsql must not phantom when host Zend lacks it.
 *
 * Run:
 *   php test/repro/maintainer_gap_pgsql_phantom.php
 *   php bin/vm.php test/repro/maintainer_gap_pgsql_phantom.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_pgsql_phantom.php
 */
echo 'extension_loaded(pgsql)=', var_export(extension_loaded('pgsql'), true), "\n";
echo 'function_exists(pg_connect)=', var_export(function_exists('pg_connect'), true), "\n";
echo 'defined(PGSQL_CONNECT_FORCE_NEW)=', var_export(defined('PGSQL_CONNECT_FORCE_NEW'), true), "\n";
echo 'function_exists(pg_set_error_context_visibility)=',
    var_export(function_exists('pg_set_error_context_visibility'), true), "\n";
$c = get_defined_constants(true);
echo 'bucket(pgsql)=', var_export(isset($c['pgsql']), true), "\n";
