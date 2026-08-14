<?php
/**
 * #30828 — SimpleXML methods/import excess argc → Zend ArgumentCountError.
 *
 * User args exclude $this; php-src ext/simplexml/simplexml.stub.php + ext/dom/php_dom.stub.php.
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

$x = simplexml_load_string('<r><a/></r>');
$d = new DOMDocument();
$d->loadXML('<r/>');

msg(static function () use ($x) {
    $x->children(null, false, 1);
});
msg(static function () use ($x) {
    $x->attributes(null, false, 1);
});
msg(static function () use ($x) {
    $x->xpath('//a', 1);
});
msg(static function () use ($x) {
    $x->registerXPathNamespace('a', 'urn:a', 1);
});
msg(static function () use ($x) {
    $x->addChild('a', 'b', null, 1);
});
msg(static function () use ($x) {
    $x->addAttribute('a', 'b', null, 1);
});
msg(static function () use ($x) {
    dom_import_simplexml($x, 1);
});
msg(static function () use ($d) {
    simplexml_import_dom($d, null, 1);
});
msg(static function () {
    simplexml_load_string('<r/>', 'SimpleXMLElement', 0, '', false, 1);
});
msg(static function () use ($x) {
    $x->getName(1);
});
msg(static function () use ($x) {
    $x->count(1);
});
msg(static function () use ($x) {
    $x->getNamespaces(false, 1);
});
msg(static function () use ($x) {
    $x->getDocNamespaces(false, true, 1);
});
msg(static function () use ($x) {
    $x->asXML('/tmp/sxe30828.xml', 1);
});
msg(static function () use ($x) {
    $x->saveXML('/tmp/sxe30828b.xml', 1);
});
msg(static function () {
    simplexml_load_file('/tmp/nope-30828.xml', 'SimpleXMLElement', 0, '', false, 1);
});
msg(static function () {
    new SimpleXMLElement('<r/>', 0, false, '', false, 1);
});
msg(static function () use ($x) {
    $x->__toString(1);
});
msg(static function () use ($x) {
    $x->hasChildren(1);
});
msg(static function () use ($x) {
    $x->getChildren(1);
});

// Legal arities still work.
$ok = simplexml_load_string('<r><a/></r>');
$ok->children();
$ok->children(null, false);
$ok->xpath('//a');
$ok->registerXPathNamespace('p', 'urn:p');
$ok->addChild('b');
$ok->addChild('c', 'v', null);
$ok->addAttribute('k', 'v');
$n = dom_import_simplexml($ok);
$back = simplexml_import_dom($n, null);
echo 'ok=', $ok->getName(), ',', $ok->count(), ',', $back->getName(), "\n";
