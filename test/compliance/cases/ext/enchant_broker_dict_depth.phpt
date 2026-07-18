--TEST--
ext/enchant broker list_dicts/describe + dict session add (#20613)
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
@enchant_broker_free($b);
if (!function_exists('enchant_broker_list_dicts')) die('skip no enchant_broker_list_dicts');
?>
--FILE--
<?php
declare(strict_types=1);
$broker = enchant_broker_init();
$dicts = enchant_broker_list_dicts($broker);
echo 'dicts=', (int) (count($dicts) > 0), "\n";
echo 'dict_keys=', implode(',', array_keys($dicts[0] ?? [])), "\n";
$providers = enchant_broker_describe($broker);
echo 'providers=', (int) (count($providers) > 0), "\n";
echo 'prov_keys=', implode(',', array_keys($providers[0] ?? [])), "\n";
$dict = enchant_broker_request_dict($broker, 'en_US');
$info = enchant_dict_describe($dict);
echo 'lang=', $info['lang'] ?? '', "\n";
$word = 'xyzzyenchant20613';
echo 'before=', (int) enchant_dict_is_added($dict, $word), "\n";
enchant_dict_add_to_session($dict, $word);
echo 'after=', (int) enchant_dict_is_added($dict, $word), "\n";
echo 'check=', (int) enchant_dict_check($dict, $word), "\n";
$sugg = [];
echo 'qc=', (int) enchant_dict_quick_check($dict, 'tset', $sugg), "\n";
echo 'sugg=', (int) (count($sugg) > 0), "\n";
enchant_broker_free_dict($dict);
enchant_broker_free($broker);
echo "ok\n";
?>
--EXPECT--
dicts=1
dict_keys=lang_tag,provider_name,provider_desc,provider_file
providers=1
prov_keys=name,desc,file
lang=en_US
before=0
after=1
check=1
qc=0
sugg=1
ok
