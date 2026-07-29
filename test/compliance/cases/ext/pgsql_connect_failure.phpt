--TEST--
ext/pgsql pg_connect failure + pg_last_error (#3741)
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
