--TEST--
ext/pgsql pg_connect failure + pg_last_error (#3741)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
$bad = @pg_connect('host=127.0.0.1 port=1 dbname=nope user=nope password=nope connect_timeout=1');
echo 'bad=', var_export($bad, true), "\n";
$err = pg_last_error();
echo 'has_err=', (int) ('' !== $err), "\n";
echo 'ext=', (int) extension_loaded('pgsql'), "\n";
?>
--EXPECT--
bad=false
has_err=1
ext=1
