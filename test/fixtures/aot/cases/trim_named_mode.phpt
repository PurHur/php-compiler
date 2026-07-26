--TEST--
AOT: trim() named characters: (Zend stub; no phantom mode) (#23224)
--FILE--
<?php
$s = '  a  ';
echo trim($s, characters: ' '), "\n";
echo ltrim($s, characters: ' '), "\n";
echo rtrim($s, characters: ' '), "\n";
--EXPECT--
a
a  
  a
