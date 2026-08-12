--TEST--
DOMNode mutation/importNode null TypeError text (#30410, ext/dom/node.c)
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$e = $d->documentElement;
$cases = [
    ['appendChild', static function () use ($e) { $e->appendChild(null); }],
    ['insertBefore', static function () use ($e) { $e->insertBefore(null); }],
    ['replaceChild', static function () use ($e) { $e->replaceChild(null, $e->firstChild); }],
    ['removeChild', static function () use ($e) { $e->removeChild(null); }],
    ['importNode', static function () use ($d) { $d->importNode(null); }],
];
foreach ($cases as [$name, $fn]) {
    try {
        $fn();
        echo $name, "=fail\n";
    } catch (Throwable $ex) {
        echo $name, '=', get_class($ex), ':', $ex->getMessage(), "\n";
    }
}
--EXPECT--
appendChild=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, null given
insertBefore=TypeError:DOMNode::insertBefore(): Argument #1 ($node) must be of type DOMNode, null given
replaceChild=TypeError:DOMNode::replaceChild(): Argument #1 ($node) must be of type DOMNode, null given
removeChild=TypeError:DOMNode::removeChild(): Argument #1 ($child) must be of type DOMNode, null given
importNode=TypeError:DOMDocument::importNode(): Argument #1 ($node) must be of type DOMNode, null given
