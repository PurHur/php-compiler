--TEST--
stdlib strcspn/strspn/strpbrk Reflection/named params (#23400, basic_functions.stub.php)
--FILE--
<?php
$rc = new ReflectionFunction('strcspn');
echo 'strcspn=';
foreach ($rc->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
echo strcspn(string: 'abc', characters: 'c'), "\n";
echo strcspn(string: 'abcdef', characters: 'x', offset: 2, length: 3), "\n";
try {
    strcspn(str: 'abc', mask: 'c');
    echo "legacy strcspn ok\n";
} catch (Throwable $e) {
    echo 'legacy strcspn ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}

$rs = new ReflectionFunction('strspn');
echo 'strspn=';
foreach ($rs->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
echo strspn(string: 'abc', characters: 'ab'), "\n";
try {
    strspn(str: 'abc', mask: 'ab');
    echo "legacy strspn ok\n";
} catch (Throwable $e) {
    echo 'legacy strspn ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}

$rp = new ReflectionFunction('strpbrk');
echo 'strpbrk=';
foreach ($rp->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(strpbrk(string: 'abc', characters: 'b'));
echo "\n";
try {
    strpbrk(haystack: 'abc', char_list: 'b');
    echo "legacy strpbrk ok\n";
} catch (Throwable $e) {
    echo 'legacy strpbrk ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
strcspn=string,characters,offset,length,
2
3
legacy strcspn ERR=Error: Unknown named parameter $str
strspn=string,characters,offset,length,
2
legacy strspn ERR=Error: Unknown named parameter $str
strpbrk=string,characters,
'bc'
legacy strpbrk ERR=Error: Unknown named parameter $haystack
