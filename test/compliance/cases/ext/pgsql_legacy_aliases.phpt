--TEST--
ext/pgsql legacy PHP_FALIAS names exist beside modern APIs (#22219)
--SKIPIF--
<?php
// Host Zend often lacks ext/pgsql; in-tree path uses PHP_COMPILER_ENABLE_PGSQL (#24994).
if (!extension_loaded('pgsql')) {
    $en = getenv('PHP_COMPILER_ENABLE_PGSQL');
    if (!is_string($en) || '' === trim($en) || in_array(strtolower(trim($en)), ['0', 'false', 'off', 'no'], true)) {
        die('skip pgsql withheld');
    }
}
?>
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
--FILE--
<?php
declare(strict_types=1);
$pairs = [
    ['pg_query', 'pg_exec'],
    ['pg_num_rows', 'pg_numrows'],
    ['pg_num_fields', 'pg_numfields'],
    ['pg_affected_rows', 'pg_cmdtuples'],
    ['pg_field_name', 'pg_fieldname'],
    ['pg_field_num', 'pg_fieldnum'],
    ['pg_field_size', 'pg_fieldsize'],
    ['pg_field_type', 'pg_fieldtype'],
    ['pg_field_prtlen', 'pg_fieldprtlen'],
    ['pg_field_is_null', 'pg_fieldisnull'],
    ['pg_free_result', 'pg_freeresult'],
    ['pg_last_oid', 'pg_getlastoid'],
    ['pg_client_encoding', 'pg_clientencoding'],
    ['pg_set_client_encoding', 'pg_setclientencoding'],
    ['pg_last_error', 'pg_errormessage'],
    ['pg_lo_open', 'pg_loopen'],
    ['pg_lo_close', 'pg_loclose'],
    ['pg_lo_create', 'pg_locreate'],
    ['pg_lo_read', 'pg_loread'],
    ['pg_lo_write', 'pg_lowrite'],
    ['pg_lo_unlink', 'pg_lounlink'],
    ['pg_lo_import', 'pg_loimport'],
    ['pg_lo_export', 'pg_loexport'],
    ['pg_lo_read_all', 'pg_loreadall'],
    ['pg_fetch_result', 'pg_result'],
];
foreach ($pairs as [$modern, $alias]) {
    $m = function_exists($modern) ? '1' : '0';
    $a = function_exists($alias) ? '1' : '0';
    echo $alias, '=', $a, ' modern=', $m, "\n";
}
?>
--EXPECT--
pg_exec=1 modern=1
pg_numrows=1 modern=1
pg_numfields=1 modern=1
pg_cmdtuples=1 modern=1
pg_fieldname=1 modern=1
pg_fieldnum=1 modern=1
pg_fieldsize=1 modern=1
pg_fieldtype=1 modern=1
pg_fieldprtlen=1 modern=1
pg_fieldisnull=1 modern=1
pg_freeresult=1 modern=1
pg_getlastoid=1 modern=1
pg_clientencoding=1 modern=1
pg_setclientencoding=1 modern=1
pg_errormessage=1 modern=1
pg_loopen=1 modern=1
pg_loclose=1 modern=1
pg_locreate=1 modern=1
pg_loread=1 modern=1
pg_lowrite=1 modern=1
pg_lounlink=1 modern=1
pg_loimport=1 modern=1
pg_loexport=1 modern=1
pg_loreadall=1 modern=1
pg_result=1 modern=1
