<?php

declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$stmt = $pdo->query('select 1');
$row = $pdo->query('select 1 as a')->fetch(PDO::FETCH_LAZY);

foreach ([
    'PDO' => $pdo,
    'PDOStatement' => $stmt,
    'PDORow' => $row,
] as $name => $obj) {
    try {
        serialize($obj);
        echo $name." serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $name.' serialize '.get_class($e).':'.$e->getMessage()."\n";
    }
    $payload = 'O:'.strlen($name).':"'.$name.'":0:{}';
    try {
        unserialize($payload);
        echo $name." unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $name.' unserialize '.get_class($e).':'.$e->getMessage()."\n";
    }
}
