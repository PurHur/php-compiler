--TEST--
ext/pspell personal/session/config function_exists (#22229)
--SKIPIF--
<?php
if (!function_exists('pspell_new')) die('skip no pspell (libaspell FFI)');
?>
--FILE--
<?php
foreach ([
    'pspell_add_to_personal',
    'pspell_add_to_session',
    'pspell_clear_session',
    'pspell_save_wordlist',
    'pspell_store_replacement',
    'pspell_config_create',
    'pspell_config_mode',
    'pspell_config_ignore',
    'pspell_config_personal',
    'pspell_config_runtogether',
    'pspell_config_dict_dir',
    'pspell_config_data_dir',
    'pspell_config_repl',
    'pspell_config_save_repl',
    'pspell_new_personal',
    'pspell_new_config',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
echo 'cfg_class=', var_export(class_exists('PSpell\\Config'), true), "\n";
?>
--EXPECT--
pspell_add_to_personal=true
pspell_add_to_session=true
pspell_clear_session=true
pspell_save_wordlist=true
pspell_store_replacement=true
pspell_config_create=true
pspell_config_mode=true
pspell_config_ignore=true
pspell_config_personal=true
pspell_config_runtogether=true
pspell_config_dict_dir=true
pspell_config_data_dir=true
pspell_config_repl=true
pspell_config_save_repl=true
pspell_new_personal=true
pspell_new_config=true
cfg_class=true
