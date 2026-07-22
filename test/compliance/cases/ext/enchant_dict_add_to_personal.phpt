--TEST--
ext/enchant enchant_dict_add_to_personal alias of enchant_dict_add (#22270)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) die('skip no ffi');
if (!function_exists('enchant_broker_init')) die('skip no enchant (libenchant FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
echo 'add=', function_exists('enchant_dict_add') ? 'Y' : 'N', "\n";
echo 'personal=', function_exists('enchant_dict_add_to_personal') ? 'Y' : 'N', "\n";
$broker = @enchant_broker_init();
if (false === $broker || !@enchant_broker_dict_exists($broker, 'en_US')) {
    echo "no_dict\n";
    if (false !== $broker) {
        @enchant_broker_free($broker);
    }
    echo "ok\n";
    exit(0);
}
$dict = enchant_broker_request_dict($broker, 'en_US');
$word = 'phpc_personal_alias_' . bin2hex(random_bytes(4));
enchant_dict_add_to_personal($dict, $word);
echo 'is_added=', enchant_dict_is_added($dict, $word) ? 'Y' : 'N', "\n";
enchant_broker_free_dict($dict);
enchant_broker_free($broker);
echo "ok\n";
?>
--EXPECTF--
add=Y
personal=Y
%aok
