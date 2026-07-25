--TEST--
sysvmsg/sysvsem/sysvshm/shmop serialize()/unserialize() reject (issue #23132)
--FILE--
<?php
$key = ftok(__FILE__, 't');
if ($key === -1) {
    $key = 0x7a001;
}

$objects = [
    'SysvMessageQueue' => msg_get_queue($key),
    'SysvSemaphore' => sem_get($key),
    'SysvSharedMemory' => shm_attach($key, 1024),
    'Shmop' => shmop_open($key, 'c', 0644, 64),
];

foreach ($objects as $name => $o) {
    if ($o === false) {
        echo $name, ":skip\n";
        continue;
    }
    try {
        serialize($o);
        echo $name, ":serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $name, ':serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}

$payloads = [
    'SysvMessageQueue' => 'O:16:"SysvMessageQueue":0:{}',
    'SysvSemaphore' => 'O:13:"SysvSemaphore":0:{}',
    'SysvSharedMemory' => 'O:16:"SysvSharedMemory":0:{}',
    'Shmop' => 'O:5:"Shmop":0:{}',
];
foreach ($payloads as $name => $wire) {
    try {
        unserialize($wire);
        echo $name, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $name, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
SysvMessageQueue:serialize:Exception:Serialization of 'SysvMessageQueue' is not allowed
SysvSemaphore:serialize:Exception:Serialization of 'SysvSemaphore' is not allowed
SysvSharedMemory:serialize:Exception:Serialization of 'SysvSharedMemory' is not allowed
Shmop:serialize:Exception:Serialization of 'Shmop' is not allowed
SysvMessageQueue:unserialize:Exception:Unserialization of 'SysvMessageQueue' is not allowed
SysvSemaphore:unserialize:Exception:Unserialization of 'SysvSemaphore' is not allowed
SysvSharedMemory:unserialize:Exception:Unserialization of 'SysvSharedMemory' is not allowed
Shmop:unserialize:Exception:Unserialization of 'Shmop' is not allowed
