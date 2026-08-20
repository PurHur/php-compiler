--TEST--
AOT: htmlentities() accepts UTF-8 encoding argument (#32063, ext/standard/html.c)
--FILE--
<?php
echo htmlentities('<tag>', ENT_QUOTES, 'UTF-8'), "\n";
echo htmlentities('<b>"\'</b>', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), "\n";
--EXPECT--
&lt;tag&gt;
&lt;b&gt;&quot;&#039;&lt;/b&gt;
