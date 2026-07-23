<?php
/** Repro #22456 — mysqli_sql_exception SQLSTATE surface. */
echo 'getSqlState=', (int) method_exists('mysqli_sql_exception', 'getSqlState'), "\n";
echo 'sqlstate_prop=', (int) property_exists('mysqli_sql_exception', 'sqlstate'), "\n";
$e = new mysqli_sql_exception('duplicate key', 1062);
echo 'code=', $e->getCode(), "\n";
echo 'state=', method_exists($e, 'getSqlState') ? $e->getSqlState() : 'MISSING', "\n";
