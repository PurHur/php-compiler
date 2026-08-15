--TEST--
ext/pgsql 1-arg pg_escape_* FETCH_DEFAULT_LINK deprecation + bytea (#31184)
--SKIPIF--
<?php
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
error_reporting(E_ALL);
ini_set('display_errors', '1');

$input = "a'b";
$deps = [];
set_error_handler(static function (int $errno, string $errstr) use (&$deps): bool {
    if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
        $deps[] = $errstr;
        return true;
    }
    return false;
});

$s = pg_escape_string($input);
$b = pg_escape_bytea($input);
echo 'string_hex=', bin2hex($s), "\n";
echo 'bytea_hex=', bin2hex($b), "\n";
echo 'bytea_matches_string=', (int) ($s === $b), "\n";
echo 'deps=', count($deps), "\n";
foreach ($deps as $d) {
    echo (str_contains($d, 'Automatic fetching of PostgreSQL connection is deprecated') ? 'dep=ok' : 'dep=bad'), "\n";
}

try {
    pg_escape_literal($input);
    echo "literal=fail\n";
} catch (Error $e) {
    echo 'literal=', $e->getMessage(), "\n";
}
try {
    pg_escape_identifier($input);
    echo "identifier=fail\n";
} catch (Error $e) {
    echo 'identifier=', $e->getMessage(), "\n";
}
echo 'deps_after_literal_id=', count($deps), "\n";
?>
--EXPECT--
string_hex=61272762
bytea_hex=61272762
bytea_matches_string=1
deps=2
dep=ok
dep=ok
literal=No PostgreSQL connection opened yet
identifier=No PostgreSQL connection opened yet
deps_after_literal_id=4
