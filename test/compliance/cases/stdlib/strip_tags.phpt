--TEST--
stdlib strip_tags()
--FILE--
<?php
echo strip_tags('<script>alert(1)</script>hello'), "\n";
echo strip_tags('<b>x</b><i>y</i>', '<b>'), "\n";
echo strip_tags('<p>a</p><br/>b', '<p><br>'), "\n";
echo strip_tags('a<!--hidden-->b'), "\n";
echo strip_tags('not a tag < incomplete'), "\n";
--EXPECT--
alert(1)hello
<b>x</b>y
<p>a</p><br/>b
ab
not a tag < incomplete
