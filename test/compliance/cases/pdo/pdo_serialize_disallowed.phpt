--TEST--
PDO/PDOStatement/PDORow serialize()/unserialize() reject (issue #23103, ext/pdo stubs)
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$stmt = $pdo->query('select 1');
$row = $pdo->query('select 1 as a')->fetch(PDO::FETCH_LAZY);

try {
    serialize($pdo);
    echo "PDO serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:3:"PDO":0:{}');
    echo "PDO unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize($stmt);
    echo "PDOStatement serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:12:"PDOStatement":0:{}');
    echo "PDOStatement unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize($row);
    echo "PDORow serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:6:"PDORow":0:{}');
    echo "PDORow unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'PDO' is not allowed
Exception:Unserialization of 'PDO' is not allowed
Exception:Serialization of 'PDOStatement' is not allowed
Exception:Unserialization of 'PDOStatement' is not allowed
Exception:Serialization of 'PDORow' is not allowed
Exception:Unserialization of 'PDORow' is not allowed
