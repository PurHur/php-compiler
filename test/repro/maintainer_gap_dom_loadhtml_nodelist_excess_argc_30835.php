<?php
/**
 * #30835 — loadHTML(File)/NodeList::item + NamedNodeMap excess argc → Zend ArgumentCountError.
 *
 * User args exclude $this; php-src ext/dom document.c / nodelist.c / namednodemap.c.
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

$d = new DOMDocument();
msg(static function () use ($d) {
    $d->loadHTML('<p>x</p>', 0, 'x');
});
msg(static function () use ($d) {
    $d->loadHTMLFile('/etc/hosts', 0, 'x');
});

$d->loadHTML('<a id="i">x</a>');
$list = $d->getElementsByTagName('a');
msg(static function () use ($list) {
    $list->item(0, 'x');
});
$el = $list->item(0);
$map = $el->attributes;
msg(static function () use ($map) {
    $map->item(0, 'x');
});
msg(static function () use ($map) {
    $map->getNamedItem('id', 'x');
});

// Legal arities still work.
$d2 = new DOMDocument();
$ok = $d2->loadHTML('<p id="ok">t</p>');
echo $ok ? 'loadOK' : 'loadFAIL', "\n";
$n = $d2->getElementsByTagName('p')->item(0);
echo null !== $n ? $n->getAttribute('id') : 'null', "\n";
$attr = $n->attributes->getNamedItem('id');
echo null !== $attr ? $attr->nodeValue : 'null', "\n";
