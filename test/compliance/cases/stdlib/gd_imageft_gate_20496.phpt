--TEST--
stdlib gd imageft* registration gated with imagettf* (#20496)
--FILE--
<?php
$ft = function_exists('imagettfbbox');
echo 'gate=', (int) $ft, "\n";
echo 'imageftbbox=', (int) (function_exists('imageftbbox') === $ft), "\n";
echo 'imagefttext=', (int) (function_exists('imagefttext') === $ft), "\n";
echo 'ok', "\n";
?>
--EXPECTF--
gate=%d
imageftbbox=1
imagefttext=1
ok
