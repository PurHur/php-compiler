--TEST--
stdlib get_cfg_var() PERDIR/SYSTEM php.ini values (#14845, ext/standard/info.c)
--FILE--
<?php
echo get_cfg_var('upload_max_filesize'), "\n";
echo get_cfg_var('post_max_size'), "\n";
echo get_cfg_var('allow_url_fopen'), "\n";
echo get_cfg_var('engine'), "\n";
echo get_cfg_var('zend.assertions'), "\n";
echo get_cfg_var('zend.enable_gc'), "\n";
echo get_cfg_var('zend.exception_ignore_args'), "\n";
echo get_cfg_var('no_such_cfg_key_xyz') === false ? 'unknown_false' : 'unknown_bad', "\n";
--EXPECT--
2M
8M
1
1
1
1
1
unknown_false
