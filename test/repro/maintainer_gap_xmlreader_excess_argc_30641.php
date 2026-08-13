<?php
/**
 * XMLReader excess argc → ArgumentCountError (#30641).
 * Prints Zend-shaped messages (user-arg counts, excluding $this).
 */
error_reporting(E_ALL);
function msg(callable $fn): string
{
    try {
        $fn();

        return 'NO_THROW';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

$uri = 'data://text/plain,'.rawurlencode('<root a="1" xmlns:p="urn:x"><child/></root>');
$r = new XMLReader();
$r->open($uri);
$r->read();

echo msg(function () {
    $x = new XMLReader();
    $x->open('data://text/plain,'.rawurlencode('<a/>'), null, 0, 'x');
}), "\n";
echo msg(function () {
    $x = new XMLReader();
    $x->XML('<a/>', null, 0, 'x');
}), "\n";
echo msg(function () use ($uri) {
    $x = new XMLReader();
    $x->open($uri);
    $x->close(1);
}), "\n";
echo msg(function () use ($uri) {
    $x = new XMLReader();
    $x->open($uri);
    $x->read(1);
}), "\n";
echo msg(function () use ($uri) {
    $x = new XMLReader();
    $x->open($uri);
    $x->read();
    $x->next(null, 1);
}), "\n";
echo msg(function () use ($uri) {
    $x = new XMLReader();
    $x->open($uri);
    $x->read();
    $x->expand(null, 1);
}), "\n";
echo msg(function () use ($r) {
    $r->getAttribute('a', 1);
}), "\n";
echo msg(function () use ($r) {
    $r->getAttributeNo(0, 1);
}), "\n";
echo msg(function () use ($r) {
    $r->getAttributeNs('a', 'urn:x', 1);
}), "\n";
echo msg(function () use ($r) {
    $r->isValid(1);
}), "\n";
echo msg(function () use ($r) {
    $r->readInnerXml(1);
}), "\n";
echo msg(function () use ($r) {
    $r->readOuterXml(1);
}), "\n";
echo msg(function () use ($r) {
    $r->readString(1);
}), "\n";
echo msg(function () use ($r) {
    $r->moveToAttribute('a', 1);
}), "\n";
echo msg(function () use ($r) {
    $r->moveToAttributeNo(0, 1);
}), "\n";
echo msg(function () use ($r) {
    $r->moveToAttributeNs('a', 'urn:x', 1);
}), "\n";
echo msg(function () use ($r) {
    $r->moveToFirstAttribute(1);
}), "\n";
echo msg(function () use ($r) {
    $r->moveToFirstAttribute();
    $r->moveToNextAttribute(1);
}), "\n";
echo msg(function () use ($r) {
    $r->moveToElement(1);
}), "\n";
echo msg(function () use ($r) {
    $r->lookupNamespace(null, 1);
}), "\n";
echo msg(function () use ($r) {
    $r->setParserProperty(XMLReader::LOADDTD, true, 1);
}), "\n";
echo msg(function () use ($r) {
    $r->getParserProperty(XMLReader::LOADDTD, 1);
}), "\n";
echo msg(function () use ($r) {
    $r->setSchema(null, 1);
}), "\n";
echo msg(function () use ($r) {
    $r->setRelaxNGSchema(null, 1);
}), "\n";
echo msg(function () use ($r) {
    $r->setRelaxNGSchemaSource(null, 1);
}), "\n";

// Legal arities still work
$ok = new XMLReader();
$ok->XML('<z id="1"/>');
$ok->read();
echo ($ok->getAttribute('id') === '1' ? 'LEGAL_OK' : 'LEGAL_FAIL'), "\n";
