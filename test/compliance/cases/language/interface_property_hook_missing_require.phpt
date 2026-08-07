--TEST--
Language: interface hooked property omission fatals across require (#28374, re-#6965, zend_inheritance.c)
--FILE--
<?php
$iface = sys_get_temp_dir().'/phpc_28374_iface_'.getmypid().'.php';
$bad = sys_get_temp_dir().'/phpc_28374_bad_'.getmypid().'.php';
file_put_contents($iface, "<?php\ninterface I { public string \$name { get; set; } }\n");
file_put_contents($bad, "<?php\nclass BadI implements I {}\necho \"BadI ok\\n\";\n");
require $iface;
require $bad;
@unlink($iface);
@unlink($bad);
--EXPECTF--
PHP Fatal error:  Class BadI must implement 1 interface property (I::$name { get; set; }) in %s on line %d
--EXPECT_EXIT--
255
