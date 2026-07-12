--TEST--
stdlib get_cfg_var() returns false for compile-time-only keys not in cfg table (#17881, ext/standard/basic_functions.c)
--FILE--
<?php
echo get_cfg_var('extension_dir') === false ? "extension_dir_false\n" : "extension_dir_bad\n";
echo get_cfg_var('error_log') === false ? "error_log_false\n" : "error_log_bad\n";
echo get_cfg_var('upload_tmp_dir') === false ? "upload_tmp_dir_false\n" : "upload_tmp_dir_bad\n";
echo is_string(get_cfg_var('cfg_file_path')) && get_cfg_var('cfg_file_path') !== '' ? "cfg_file_path_ok\n" : "cfg_file_path_bad\n";
echo ini_get('error_log') === '' ? "ini_get_error_log_empty\n" : "ini_get_error_log_bad\n";
--EXPECT--
extension_dir_false
error_log_false
upload_tmp_dir_false
cfg_file_path_ok
ini_get_error_log_empty
