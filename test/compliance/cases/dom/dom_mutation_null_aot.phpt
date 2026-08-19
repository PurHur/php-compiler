--TEST--
stdlib DOM mutation/importNode(null) TypeError (#32558 leftover of #30410, ext/dom/node.c)
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$e = $d->documentElement;
try {
    $d->appendChild(null);
    echo "docAppendChild=fail\n";
} catch (Throwable $ex) {
    echo 'docAppendChild=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->appendChild(null);
    echo "appendChild=fail\n";
} catch (Throwable $ex) {
    echo 'appendChild=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->insertBefore(null);
    echo "insertBefore=fail\n";
} catch (Throwable $ex) {
    echo 'insertBefore=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->replaceChild(null, $e->firstChild);
    echo "replaceChild=fail\n";
} catch (Throwable $ex) {
    echo 'replaceChild=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->removeChild(null);
    echo "removeChild=fail\n";
} catch (Throwable $ex) {
    echo 'removeChild=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $d->importNode(null);
    echo "importNode=fail\n";
} catch (Throwable $ex) {
    echo 'importNode=', get_class($ex), ':', $ex->getMessage(), "\n";
}
--EXPECT--
docAppendChild=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, null given
appendChild=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, null given
insertBefore=TypeError:DOMNode::insertBefore(): Argument #1 ($node) must be of type DOMNode, null given
replaceChild=TypeError:DOMNode::replaceChild(): Argument #1 ($node) must be of type DOMNode, null given
removeChild=TypeError:DOMNode::removeChild(): Argument #1 ($child) must be of type DOMNode, null given
importNode=TypeError:DOMDocument::importNode(): Argument #1 ($node) must be of type DOMNode, null given
