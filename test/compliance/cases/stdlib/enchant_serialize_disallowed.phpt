--TEST--
EnchantBroker/EnchantDictionary serialize()/unserialize() reject (issue #23112, ext/enchant/enchant.stub.php)
--FILE--
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
if (!$dict) {
    throw new Error('enchant_broker_request_pwl_dict failed');
}
try {
    serialize($dict);
    echo "EnchantDictionary:serialize:no-throw\n";
} catch (Throwable $e) {
    echo 'EnchantDictionary:serialize:', get_class($e), ':', $e->getMessage(), "\n";
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
--EXPECT--
EnchantBroker:serialize:Exception:Serialization of 'EnchantBroker' is not allowed
EnchantDictionary:serialize:Exception:Serialization of 'EnchantDictionary' is not allowed
EnchantBroker:unserialize:Exception:Unserialization of 'EnchantBroker' is not allowed
EnchantDictionary:unserialize:Exception:Unserialization of 'EnchantDictionary' is not allowed
