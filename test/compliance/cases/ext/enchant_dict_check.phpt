--TEST--
ext/enchant enchant_dict_check misspelling (#6230)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) die('skip no ffi');
if (!function_exists('enchant_broker_init')) die('skip no enchant (libenchant FFI)');
$b = @enchant_broker_init();
if (false === $b) die('skip enchant_broker_init failed');
if (!@enchant_broker_dict_exists($b, 'en_US')) {
    @enchant_broker_free($b);
    die('skip no en_US dictionary');
}
$d = @enchant_broker_request_dict($b, 'en_US');
if (false === $d) {
    @enchant_broker_free($b);
    die('skip cannot request en_US');
}
@enchant_broker_free_dict($d);
@enchant_broker_free($b);
?>
--FILE--
<?php
declare(strict_types=1);
$broker = enchant_broker_init();
echo 'init=', (int) (false !== $broker), "\n";
echo 'ce=', (int) class_exists('EnchantBroker'), "\n";
$dict = enchant_broker_request_dict($broker, 'en_US');
echo 'test=', (int) enchant_dict_check($dict, 'test'), "\n";
echo 'tset=', (int) enchant_dict_check($dict, 'tset'), "\n";
enchant_broker_free_dict($dict);
enchant_broker_free($broker);
echo "ok\n";
?>
--EXPECT--
init=1
ce=1
test=1
tset=0
ok
