--TEST--
Phar/PharData/PharFileInfo serialize()/unserialize() reject (issue #23154, ext/phar/phar.stub.php)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_phar_ser_' . getmypid() . '.tar';
@unlink($path);
$p = new PharData($path);
$p['x.txt'] = 'hi';
foreach (['PharData' => $p, 'PharFileInfo' => $p['x.txt']] as $label => $o) {
    try {
        serialize($o);
        echo $label, ":serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
foreach (['PharData' => 'O:8:"PharData":0:{}', 'PharFileInfo' => 'O:12:"PharFileInfo":0:{}', 'Phar' => 'O:4:"Phar":0:{}'] as $label => $payload) {
    try {
        unserialize($payload);
        echo $label, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
@unlink($path);
--EXPECT--
PharData:serialize:Exception:Serialization of 'PharData' is not allowed
PharFileInfo:serialize:Exception:Serialization of 'PharFileInfo' is not allowed
PharData:unserialize:Exception:Unserialization of 'PharData' is not allowed
PharFileInfo:unserialize:Exception:Unserialization of 'PharFileInfo' is not allowed
Phar:unserialize:Exception:Unserialization of 'Phar' is not allowed
