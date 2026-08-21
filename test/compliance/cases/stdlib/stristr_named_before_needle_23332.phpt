--TEST--
stdlib stristr() named before_needle + Reflection names (#23332)
--FILE--
<?php
$r = new ReflectionFunction('stristr');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
echo stristr(haystack: 'AbCd', needle: 'bc', before_needle: true), "\n";
echo stristr('AbCd', 'bc', true), "\n";
--EXPECT--
haystack,needle,before_needle
A
A
