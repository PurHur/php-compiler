--TEST--
named arguments on internal strlen (VM)
--FILE--
<?php
echo strlen(string: "hi");
--EXPECT--
2
