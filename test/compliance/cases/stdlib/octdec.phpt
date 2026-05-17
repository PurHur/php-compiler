--TEST--
stdlib octdec() for octal strings
--FILE--
<?php
echo octdec('0'), "\n";
echo octdec('10'), "\n";
echo octdec('77'), "\n";
echo octdec('1000'), "\n";
--EXPECT--
0
8
63
512
