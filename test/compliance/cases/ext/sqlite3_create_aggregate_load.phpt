--TEST--
SQLite3::createAggregate + loadExtension surface (#20585)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsSqlite3()) {
    die('skip SQLite3 withheld on reference profile');
}
?>
--FILE--
<?php
echo 'has_agg=', method_exists('SQLite3', 'createAggregate') ? '1' : '0', "\n";
echo 'has_load=', method_exists('SQLite3', 'loadExtension') ? '1' : '0', "\n";

$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(n INTEGER)');
$db->exec('INSERT INTO t VALUES (1),(2),(3)');

$ok = $db->createAggregate(
    'mysum',
    static function ($ctx, $rownum, $n) {
        if (null === $ctx) {
            $ctx = 0;
        }
        return (int) $ctx + (int) $n;
    },
    static function ($ctx, $rownum) {
        return (int) $ctx;
    },
    1
);
echo 'create=', $ok ? '1' : '0', "\n";
echo 'sum=', $db->querySingle('SELECT mysum(n) FROM t'), "\n";

try {
    $db->loadExtension('');
    echo "empty: no throw\n";
} catch (ValueError $e) {
    echo "empty: ValueError\n";
}
try {
    $db->loadExtension(null);
    echo "null: no throw\n";
} catch (TypeError $e) {
    echo "null: TypeError\n";
}
// Missing extension_dir / missing .so → false (host capability).
echo 'load_missing=', $db->loadExtension('definitely_missing_ext_20585') ? '1' : '0', "\n";
$db->close();
?>
--EXPECT--
has_agg=1
has_load=1
create=1
sum=6
empty: ValueError
null: TypeError
load_missing=0
