<?php
// repro #23169 — LDAP\Connection/Result/ResultEntry @not-serializable
foreach (['LDAP\\Connection', 'LDAP\\Result', 'LDAP\\ResultEntry'] as $c) {
    $o = (new ReflectionClass($c))->newInstanceWithoutConstructor();
    try {
        echo $c, ':', serialize($o), "\n";
    } catch (Throwable $e) {
        echo $c, ':', $e->getMessage(), "\n";
    }
}
foreach ([
    'LDAP\\Connection' => 'O:15:"LDAP\\Connection":0:{}',
    'LDAP\\Result' => 'O:11:"LDAP\\Result":0:{}',
    'LDAP\\ResultEntry' => 'O:16:"LDAP\\ResultEntry":0:{}',
] as $c => $wire) {
    try {
        unserialize($wire);
        echo $c, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $c, ':', $e->getMessage(), "\n";
    }
}
