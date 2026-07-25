--TEST--
LDAP Connection/Result/ResultEntry serialize()/unserialize() reject (issue #23169, ext/ldap/ldap.stub.php)
--FILE--
<?php
foreach (['LDAP\\Connection', 'LDAP\\Result', 'LDAP\\ResultEntry'] as $c) {
    $o = (new ReflectionClass($c))->newInstanceWithoutConstructor();
    try {
        serialize($o);
        echo $c, ":serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $c, ':serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$wires = [
    'LDAP\\Connection' => 'O:15:"LDAP\\Connection":0:{}',
    'LDAP\\Result' => 'O:11:"LDAP\\Result":0:{}',
    'LDAP\\ResultEntry' => 'O:16:"LDAP\\ResultEntry":0:{}',
];
foreach ($wires as $c => $wire) {
    try {
        unserialize($wire);
        echo $c, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $c, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
LDAP\Connection:serialize:Exception:Serialization of 'LDAP\Connection' is not allowed
LDAP\Result:serialize:Exception:Serialization of 'LDAP\Result' is not allowed
LDAP\ResultEntry:serialize:Exception:Serialization of 'LDAP\ResultEntry' is not allowed
LDAP\Connection:unserialize:Exception:Unserialization of 'LDAP\Connection' is not allowed
LDAP\Result:unserialize:Exception:Unserialization of 'LDAP\Result' is not allowed
LDAP\ResultEntry:unserialize:Exception:Unserialization of 'LDAP\ResultEntry' is not allowed
