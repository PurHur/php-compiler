--TEST--
JIT/AOT: get_cfg_var() PERDIR/SYSTEM php.ini values (#14845)
--FILE--
<?php
echo get_cfg_var('upload_max_filesize'), "\n";
echo get_cfg_var('zend.assertions'), "\n";
echo get_cfg_var('zend.enable_gc'), "\n";
--EXPECT--
2M
1
1
