--TEST--
htmlspecialchars/htmlentities ENT_QUOTES|ENT_SUBSTITUTE + double_encode=false (issue #11387)
--FILE--
<?php
declare(strict_types=1);
$s = '<>&"';
echo htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), "\n";
echo htmlentities($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), "\n";
--EXPECT--
&lt;&gt;&amp;&quot;
&lt;&gt;&amp;&quot;
