--TEST--
mb_str_pad/mb_lcfirst/mb_ucfirst named arguments + Reflection (VM, issue #23805)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['mb_str_pad', 'mb_lcfirst', 'mb_ucfirst'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ':', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
}
echo mb_str_pad(string: 'x', length: 5), "\n";
echo mb_lcfirst(string: 'ABC'), "\n";
echo mb_ucfirst(string: 'abc'), "\n";
echo mb_str_pad('x', 5), "\n";
echo mb_lcfirst('ABC'), "\n";
echo mb_ucfirst('abc'), "\n";
--EXPECT--
mb_str_pad:string,length,pad_string,pad_type,encoding
mb_lcfirst:string,encoding
mb_ucfirst:string,encoding
x    
aBC
Abc
x    
aBC
Abc
