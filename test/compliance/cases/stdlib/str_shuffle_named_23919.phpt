--TEST--
str_shuffle named string + Reflection (VM, issue #23919)
--FILE--
<?php
$rf = new ReflectionFunction('str_shuffle');
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE', PHP_EOL;
foreach ($rf->getParameters() as $p) {
    echo $p->getName(),
        $p->isOptional() ? '=' : '',
        ':', $p->hasType() ? (string) $p->getType() : 'NONE', PHP_EOL;
}
function shuffle_sig(string $s): string
{
    $parts = str_split($s);
    sort($parts);
    return implode('', $parts);
}
$named = str_shuffle(string: 'ab');
echo strlen($named), PHP_EOL;
echo shuffle_sig('ab') === shuffle_sig($named) ? 'perm' : 'bad', PHP_EOL;
try {
    str_shuffle(str: 'ab');
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
ret=string
string:string
2
perm
Unknown named parameter $str
