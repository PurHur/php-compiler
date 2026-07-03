--TEST--
AOT: htmlentities() default encodes apostrophe as &#039; (#15272)
--FILE--
<?php
echo htmlentities("<a&'>"), "\n";
--EXPECT--
&lt;a&amp;&#039;&gt;
