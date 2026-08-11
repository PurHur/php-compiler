--TEST--
DOMXPath::registerNamespace null TypeError under strict_types (#30301)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r/>');
$xp = new DOMXPath($d);
try {
    $xp->registerNamespace(null, 'urn:x');
    echo "prefix=fail\n";
} catch (Throwable $e) {
    echo 'prefix=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $xp->registerNamespace('p', null);
    echo "namespace=fail\n";
} catch (Throwable $e) {
    echo 'namespace=', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
prefix=TypeError:DOMXPath::registerNamespace(): Argument #1 ($prefix) must be of type string, null given
namespace=TypeError:DOMXPath::registerNamespace(): Argument #2 ($namespace) must be of type string, null given
