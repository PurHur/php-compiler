--TEST--
stdlib ini_get() CLI defaults memory_limit, max_execution_time, default_charset (#11357)
--FILE--
<?php
echo ini_get('memory_limit') === '-1' ? "ml\n" : "ml-bad\n";
echo ini_get('max_execution_time') === '0' ? "met\n" : "met-bad\n";
echo ini_get('default_charset') === 'UTF-8' ? "charset\n" : "charset-bad\n";
echo get_cfg_var('memory_limit') === '-1' ? "cfg-ml\n" : "cfg-ml-bad\n";
--EXPECT--
ml
met
charset
cfg-ml
