<?php
/**
 * #30818 — XMLWriter methods excess argc → Zend ArgumentCountError.
 *
 * User args exclude $this; php-src ext/xmlwriter/php_xmlwriter.stub.php.
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

msg(static function () {
    $w = new XMLWriter();
    $w->openMemory(1);
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startDocument('1.0', 'UTF-8', 'yes', 'x');
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startElement('a', 'x');
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startElement('a');
    $w->text('t', 'x');
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startElement('a');
    $w->endElement(1);
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->outputMemory(true, 1);
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->flush(true, 1);
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->setIndent(true, 1);
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->writeAttribute('k', 'v', 'x');
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startElementNs('p', 'n', 'urn:u', 'x');
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->writeElement('a', 'b', 'x');
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startCdata(1);
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->endDocument(1);
});
msg(static function () {
    $w = new XMLWriter();
    $w->openUri('/tmp/xw30818_excess.xml', 1);
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startDtd('r', null, null, 1);
});
msg(static function () {
    $w = new XMLWriter();
    $w->openMemory();
    $w->writeDtdEntity('e', 'x', false, null, null, null, 1);
});

// Legal arities still work.
$ok = new XMLWriter();
$ok->openMemory();
$ok->startDocument('1.0', 'UTF-8');
$ok->startElement('root');
$ok->text('hi');
$ok->endElement();
$ok->endDocument();
echo $ok->outputMemory();
