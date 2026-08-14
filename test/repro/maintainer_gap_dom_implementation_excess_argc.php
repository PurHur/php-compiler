<?php
/**
 * #31090 — DOMImplementation createDocument/hasFeature excess argc → Zend ArgumentCountError.
 *
 * php-src: ext/dom/implementation.c / php_dom.stub.php
 */
error_reporting(E_ALL);
function msg(callable $fn): void
{
    try {
        $fn();
        echo "NOERR\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}

$di = new DOMImplementation();
msg(static function () use ($di) {
    $di->createDocument(null, 'r', null, 1);
});
msg(static function () use ($di) {
    $di->hasFeature('XML', '2.0', 1);
});

// Legal arities still work.
$doc = $di->createDocument(null, 'r');
echo $doc instanceof DOMDocument ? 'createOK' : 'createFAIL', "\n";
echo $di->hasFeature('XML', '2.0') ? 'featOK' : 'featFAIL', "\n";
$doc0 = $di->createDocument();
echo $doc0 instanceof DOMDocument ? 'create0OK' : 'create0FAIL', "\n";
