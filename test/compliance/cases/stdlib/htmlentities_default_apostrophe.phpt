--TEST--
stdlib htmlentities() default ENT_QUOTES|ENT_SUBSTITUTE encodes apostrophe (#15272, ext/standard/html.c)
--FILE--
<?php
echo htmlentities("<a&'>"), "\n";
echo htmlentities("<a&'>", ENT_COMPAT), "\n";
--EXPECT--
&lt;a&amp;&#039;&gt;
&lt;a&amp;'&gt;
