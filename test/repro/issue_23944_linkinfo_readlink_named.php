<?php
/**
 * #23944 — linkinfo/readlink Zend stub name is `path` (ext/standard/link.stub.php).
 * InternalArgInfo still uses `filename`.
 */
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

$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base.'/link';
echo 'readlink=', readlink(path: $link), "\n";
echo 'linkinfo=', linkinfo(path: $link) > 0 ? 'ok' : 'fail', "\n";

try {
    readlink(filename: $link);
    echo "legacy readlink filename accepted\n";
} catch (Throwable $e) {
    echo 'legacy_readlink=', $e->getMessage(), "\n";
}
try {
    linkinfo(filename: $link);
    echo "legacy linkinfo filename accepted\n";
} catch (Throwable $e) {
    echo 'legacy_linkinfo=', $e->getMessage(), "\n";
}
