<?php
declare(strict_types=1);
/**
 * Repro for #22219 — pgsql legacy PHP_FALIAS names beside modern APIs.
 * php-src: ext/pgsql/pgsql.stub.php (@alias / Deprecated aliases).
 * Unrolled checks — AOT-friendly (foreach / Iterator::Value fails standalone).
 */
$ok = true;
$alias = 'pg_exec'; $modern = 'pg_query';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_numrows'; $modern = 'pg_num_rows';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_numfields'; $modern = 'pg_num_fields';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_cmdtuples'; $modern = 'pg_affected_rows';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_fieldname'; $modern = 'pg_field_name';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_fieldnum'; $modern = 'pg_field_num';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_fieldsize'; $modern = 'pg_field_size';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_fieldtype'; $modern = 'pg_field_type';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_fieldprtlen'; $modern = 'pg_field_prtlen';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_fieldisnull'; $modern = 'pg_field_is_null';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_freeresult'; $modern = 'pg_free_result';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_getlastoid'; $modern = 'pg_last_oid';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_clientencoding'; $modern = 'pg_client_encoding';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_setclientencoding'; $modern = 'pg_set_client_encoding';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_errormessage'; $modern = 'pg_last_error';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_loopen'; $modern = 'pg_lo_open';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_loclose'; $modern = 'pg_lo_close';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_locreate'; $modern = 'pg_lo_create';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_loread'; $modern = 'pg_lo_read';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_lowrite'; $modern = 'pg_lo_write';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_lounlink'; $modern = 'pg_lo_unlink';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_loimport'; $modern = 'pg_lo_import';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_loexport'; $modern = 'pg_lo_export';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_loreadall'; $modern = 'pg_lo_read_all';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
$alias = 'pg_result'; $modern = 'pg_fetch_result';
$m = function_exists($modern); $a = function_exists($alias);
echo $alias, '=', $a ? 'Y' : 'N', ' modern=', $m ? 'Y' : 'N', "\n";
if (!$m || !$a) { $ok = false; }
exit($ok ? 0 : 1);
