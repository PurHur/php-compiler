--TEST--
PDO::quote(null) — TypeError forward 8.4 profile (#21080, ext/pdo/pdo.stub.php)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$p = new PDO('sqlite::memory:');
try {
    var_export($p->quote(null));
    echo " null: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    var_export($p->quote(''));
    echo " empty\n";
} catch (Throwable $e) {
    echo 'empty: ', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
PDO::quote(): Argument #1 ($string) must be of type string, null given
'\'\'' empty
