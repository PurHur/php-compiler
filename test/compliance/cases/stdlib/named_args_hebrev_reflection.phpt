--TEST--
hebrev named string/max_chars_per_line + Reflection (VM, issue #23693)
--FILE--
<?php
echo hebrev(string: 'abc'), PHP_EOL;
echo hebrev(string: 'abc', max_chars_per_line: 0), PHP_EOL;
$rf = new ReflectionFunction('hebrev');
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE', PHP_EOL;
foreach ($rf->getParameters() as $p) {
    echo $p->getName(),
        $p->isOptional() ? '=' : '',
        ':', $p->hasType() ? (string) $p->getType() : 'NONE', PHP_EOL;
}
--EXPECT--
abc
abc
ret=string
string:string
max_chars_per_line=:int
