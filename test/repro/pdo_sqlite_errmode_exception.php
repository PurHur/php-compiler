<?php
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
    $pdo->exec('NOT VALID SQL');
    echo "no_throw\n";
} catch (PDOException $e) {
    echo "pdo_exception\n";
}
