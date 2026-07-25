<?php

$b = enchant_broker_init();
try {
    serialize($b);
    echo "EnchantBroker:serialize:no-throw\n";
} catch (Throwable $e) {
    echo 'EnchantBroker:serialize:', get_class($e), ':', $e->getMessage(), "\n";
}
$pwl = sys_get_temp_dir() . '/phpc_enchant_ser_' . getmypid() . '.pwl';
file_put_contents($pwl, "hello\n");
$dict = enchant_broker_request_pwl_dict($b, $pwl);
if ($dict) {
    try {
        serialize($dict);
        echo "EnchantDictionary:serialize:no-throw\n";
    } catch (Throwable $e) {
        echo 'EnchantDictionary:serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
} else {
    echo "EnchantDictionary:serialize:SKIP\n";
}
foreach ([
    'EnchantBroker' => 'O:13:"EnchantBroker":0:{}',
    'EnchantDictionary' => 'O:17:"EnchantDictionary":0:{}',
] as $label => $payload) {
    try {
        unserialize($payload);
        echo $label, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
@unlink($pwl);
