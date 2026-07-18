--TEST--
stdlib gd imagecreate palette canvas (issue #7407, #20415)
--FILE--
<?php
echo 'function_exists=', var_export(function_exists('imagecreate'), true), "\n";
echo 'extension_loaded=', var_export(extension_loaded('gd'), true), "\n";
$im = imagecreate(4, 4);
echo 'is_gdimage=', var_export($im instanceof GdImage, true), "\n";
echo 'istruecolor=', var_export(imageistruecolor($im), true), "\n";
echo 'sx=', imagesx($im), ' sy=', imagesy($im), "\n";
?>
--EXPECT--
function_exists=true
extension_loaded=true
is_gdimage=true
istruecolor=false
sx=4 sy=4
