<?php
/**
 * #31824 — DOM soft-null E_DEPRECATED must cite Zend stub parameter names, not default $value.
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    return true;
});

$d = new DOMDocument();
$d->loadXML('<a x="1"/>');
$el = $d->documentElement;

try {
    $el->attributes->getNamedItem(null);
} catch (Throwable $e) {
}
try {
    $el->attributes->getNamedItemNS(null, null);
} catch (Throwable $e) {
}
try {
    $d->saveHTMLFile(null);
} catch (Throwable $e) {
}
try {
    $d->load(null);
} catch (Throwable $e) {
}
try {
    $d->loadHTMLFile(null);
} catch (Throwable $e) {
}
try {
    $el->isSupported(null, null);
} catch (Throwable $e) {
}
try {
    (new DOMImplementation())->hasFeature(null, null);
} catch (Throwable $e) {
}
try {
    $el->getAttributeNode(null);
} catch (Throwable $e) {
}
try {
    $el->getAttributeNodeNS(null, null);
} catch (Throwable $e) {
}
try {
    $frag = $d->createDocumentFragment();
    $frag->appendXML(null);
} catch (Throwable $e) {
}
try {
    (new DOMImplementation())->createDocument(null, null);
} catch (Throwable $e) {
}

echo "ok\n";
