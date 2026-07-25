--TEST--
SQLite3/SQLite3Stmt/SQLite3Result serialize()/unserialize() reject (issue #23137)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$db = new SQLite3(':memory:');
$stmt = $db->prepare('SELECT 1');
$result = $db->query('SELECT 1');

foreach ([
    'SQLite3' => $db,
    'SQLite3Stmt' => $stmt,
    'SQLite3Result' => $result,
] as $name => $o) {
    try {
        serialize($o);
        echo $name, ":serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $name, ':serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}

foreach ([
    'SQLite3' => 'O:7:"SQLite3":0:{}',
    'SQLite3Stmt' => 'O:11:"SQLite3Stmt":0:{}',
    'SQLite3Result' => 'O:13:"SQLite3Result":0:{}',
] as $name => $wire) {
    try {
        unserialize($wire);
        echo $name, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $name, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
SQLite3:serialize:Exception:Serialization of 'SQLite3' is not allowed
SQLite3Stmt:serialize:Exception:Serialization of 'SQLite3Stmt' is not allowed
SQLite3Result:serialize:Exception:Serialization of 'SQLite3Result' is not allowed
SQLite3:unserialize:Exception:Unserialization of 'SQLite3' is not allowed
SQLite3Stmt:unserialize:Exception:Unserialization of 'SQLite3Stmt' is not allowed
SQLite3Result:unserialize:Exception:Unserialization of 'SQLite3Result' is not allowed
