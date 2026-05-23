--TEST--
stdlib quotemeta()
--FILE--
<?php
echo quotemeta(''), "\n";
echo quotemeta('hello.world'), "\n";
echo quotemeta('a+b*c?'), "\n";
echo quotemeta('[a-z]'), "\n";
echo quotemeta('back\slash'), "\n";
echo quotemeta('(foo)$'), "\n";
--EXPECT--
hello\.world
a\+b\*c\?
\[a-z\]
back\\slash
\(foo\)\$
