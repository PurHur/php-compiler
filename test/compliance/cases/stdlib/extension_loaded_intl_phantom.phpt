--TEST--
stdlib extension_loaded('intl') false without full ext/intl (#11472)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('intl'), "\n";
echo 'in_list=', (int) in_array('intl', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('intl')), "\n";
echo 'grapheme_strlen=', (int) function_exists('grapheme_strlen'), "\n";
--EXPECT--
loaded=0
in_list=0
funcs=0
grapheme_strlen=0
