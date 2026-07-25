--TEST--
PgSql Connection/Result/Lob serialize()/unserialize()/new reject (issue #23135)
--FILE--
<?php
$cases = [
    'PgSql\\Connection' => 'Cannot directly construct PgSql\\Connection, use pg_connect() or pg_pconnect() instead',
    'PgSql\\Result' => 'Cannot directly construct PgSql\\Result, use a dedicated function instead',
    'PgSql\\Lob' => 'Cannot directly construct PgSql\\Lob, use pg_lo_open() instead',
];
foreach ($cases as $cn => $expect) {
    try {
        new $cn();
        echo $cn, ":new:no-throw\n";
    } catch (Throwable $e) {
        echo $cn, ':new:', get_class($e), ':', $e->getMessage(), "\n";
    }
}

$wires = [
    'PgSql\\Connection' => 'O:16:"PgSql\\Connection":0:{}',
    'PgSql\\Result' => 'O:12:"PgSql\\Result":0:{}',
    'PgSql\\Lob' => 'O:9:"PgSql\\Lob":0:{}',
];
foreach ($wires as $cn => $wire) {
    try {
        unserialize($wire);
        echo $cn, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $cn, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
PgSql\Connection:new:Error:Cannot directly construct PgSql\Connection, use pg_connect() or pg_pconnect() instead
PgSql\Result:new:Error:Cannot directly construct PgSql\Result, use a dedicated function instead
PgSql\Lob:new:Error:Cannot directly construct PgSql\Lob, use pg_lo_open() instead
PgSql\Connection:unserialize:Exception:Unserialization of 'PgSql\Connection' is not allowed
PgSql\Result:unserialize:Exception:Unserialization of 'PgSql\Result' is not allowed
PgSql\Lob:unserialize:Exception:Unserialization of 'PgSql\Lob' is not allowed
