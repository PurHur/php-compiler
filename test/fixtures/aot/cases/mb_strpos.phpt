--TEST--
AOT: mb_strpos() multibyte forward search — hit + miss as bool false (#27187)
--FILE--
<?php
echo mb_strpos('日本語', '本'), "\n";
echo mb_strpos('abc', 'z') === false ? 'F' : 'hit', "\n";
echo mb_strpos('αβγδ', 'γ', 0, 'UTF-8'), "\n";
--EXPECT--
1
F
2
