--TEST--
linkinfo/readlink Zend stub names path + named path: (#23944, link.stub.php)
--FILE--
<?php
function dumpParams(string $fn): void
{
    $r = new ReflectionFunction($fn);
    $rt = $r->getReturnType();
    echo $fn, ' ret=', $rt ? (string) $rt : 'none';
    foreach ($r->getParameters() as $p) {
        echo ' | ', $p->getName(),
            ' type=', $p->getType() ? (string) $p->getType() : 'none',
            ' opt=', $p->isOptional() ? 'Y' : 'N';
    }
    echo "\n";
}

dumpParams('linkinfo');
dumpParams('readlink');

$link = 'test/compliance/cases/stdlib/is_link_fixture/link';
echo readlink(path: $link), "\n";
echo linkinfo(path: $link) > 0 ? "ok\n" : "fail\n";

try {
    readlink(filename: $link);
    echo "legacy readlink filename accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    linkinfo(filename: $link);
    echo "legacy linkinfo filename accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
linkinfo ret=int|false | path type=string opt=N
readlink ret=string|false | path type=string opt=N
target.txt
ok
Unknown named parameter $filename
Unknown named parameter $filename
