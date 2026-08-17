--TEST--
stdlib extension_loaded('imagick') phantom withhold (#6235)
--FILE--
<?php
echo 'loaded=', (int) extension_loaded('imagick'), "\n";
echo 'class=', (int) class_exists('Imagick'), "\n";
?>
--EXPECT--
loaded=0
class=0
