<?php
error_reporting(E_ALL);
function msg(callable $fn): void {
    try {
        $fn();
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
$d = new DOMDocument();
$d->loadXML('<r><a id="x">t</a></r>');
$e = $d->documentElement;
$a = $e->firstChild;
$x = new DOMXPath($d);
msg(static function () use ($e, $a) {
    $e->appendChild($a, 1);
});
msg(static function () use ($e, $a) {
    $e->removeChild($a, 1);
});
msg(static function () use ($e) {
    $e->cloneNode(true, 1);
});
msg(static function () use ($e) {
    $e->hasChildNodes(1);
});
msg(static function () use ($e) {
    $e->normalize(1);
});
msg(static function () use ($e, $a) {
    $e->isSameNode($a, 1);
});
msg(static function () use ($d) {
    $d->getElementById('x', 1);
});
msg(static function () use ($d) {
    $d->createElement('z', 'v', 1);
});
msg(static function () use ($d) {
    $d->createTextNode('t', 1);
});
msg(static function () use ($d) {
    $d->createAttribute('n', 1);
});
msg(static function () use ($d) {
    $d->createComment('c', 1);
});
msg(static function () use ($d) {
    $d->getElementsByTagName('a', 1);
});
msg(static function () use ($d) {
    $d->loadXML('<z/>', 0, 1);
});
msg(static function () use ($d) {
    $d->saveXML(null, 0, 1);
});
msg(static function () use ($d) {
    $d->saveHTML(null, 1);
});
msg(static function () use ($d) {
    $d->xinclude(0, 1);
});
msg(static function () use ($d) {
    $d->validate(1);
});
msg(static function () use ($e) {
    $e->setAttribute('k', 'v', 1);
});
msg(static function () use ($e) {
    $e->getAttribute('id', 1);
});
msg(static function () use ($e) {
    $e->hasAttribute('id', 1);
});
msg(static function () use ($e) {
    $e->removeAttribute('id', 1);
});
msg(static function () use ($d, $a) {
    $d->importNode($a, true, 1);
});
msg(static function () use ($e, $a) {
    $e->insertBefore($a, null, 1);
});
msg(static function () use ($e, $a) {
    $e->replaceChild($a, $a, 1);
});
msg(static function () use ($x) {
    $x->query('//a', null, false, 1);
});
msg(static function () use ($x) {
    $x->evaluate('1+1', null, false, 1);
});
msg(static function () use ($x) {
    $x->registerNamespace('p', 'u', 1);
});
