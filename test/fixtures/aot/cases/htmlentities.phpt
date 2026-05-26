--TEST--
AOT: htmlentities() (#2472)
--FILE--
<?php
echo htmlentities('<tag>'), "\n";
echo htmlentities('Tom & Jerry'), "\n";
--EXPECT--
&lt;tag&gt;
Tom &amp; Jerry
