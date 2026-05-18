--TEST--
stdlib strrev()
--FILE--
<?php
echo strrev(''), "\n";
echo strrev('ab'), "\n";
echo strrev('hello'), "\n";
--EXPECT--

ba
olleh
