--TEST--
AOT: array_merge_recursive() scalar+array preserves string subkeys (#6665)
--FILE--
<?php
$a = ['color' => 'red'];
$b = ['color' => ['favorite' => 'green']];
$r = array_merge_recursive($a, $b);
echo $r['color'][0], "\n";
echo $r['color']['favorite'], "\n";
echo array_key_exists('favorite', $r['color']) ? 'yes' : 'no', "\n";
--EXPECT--
red
green
yes
