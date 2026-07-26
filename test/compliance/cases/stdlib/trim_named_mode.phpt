--TEST--
stdlib trim/ltrim/rtrim() reject phantom named mode (issue #23224; reverts #13045 vs php-src)
--FILE--
<?php
$s = '  a  ';
echo trim($s, characters: ' '), "\n";
echo ltrim($s, characters: ' '), "\n";
echo rtrim($s, characters: ' '), "\n";
try {
    trim($s, characters: ' ', mode: 1);
    echo "mode_accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
a
a  
  a
Unknown named parameter $mode
