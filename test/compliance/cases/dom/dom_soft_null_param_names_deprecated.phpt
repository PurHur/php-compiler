--TEST--
DOM soft-null deprecation cites Zend stub param names not $value (#31824)
--FILE--
<?php
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

try { $el->attributes->getNamedItem(null); } catch (Throwable $e) {}
try { $el->attributes->getNamedItemNS(null, null); } catch (Throwable $e) {}
try { $d->saveHTMLFile(null); } catch (Throwable $e) {}
try { $d->load(null); } catch (Throwable $e) {}
try { $d->loadHTMLFile(null); } catch (Throwable $e) {}
try { $el->isSupported(null, null); } catch (Throwable $e) {}
try { (new DOMImplementation())->hasFeature(null, null); } catch (Throwable $e) {}
try { $el->getAttributeNode(null); } catch (Throwable $e) {}
try { $el->getAttributeNodeNS(null, null); } catch (Throwable $e) {}
try {
    $frag = $d->createDocumentFragment();
    $frag->appendXML(null);
} catch (Throwable $e) {}
try { (new DOMImplementation())->createDocument(null, null); } catch (Throwable $e) {}
?>
--EXPECT--
DEP:DOMNamedNodeMap::getNamedItem(): Passing null to parameter #1 ($qualifiedName) of type string is deprecated
DEP:DOMNamedNodeMap::getNamedItemNS(): Passing null to parameter #2 ($localName) of type string is deprecated
DEP:DOMDocument::saveHTMLFile(): Passing null to parameter #1 ($filename) of type string is deprecated
DEP:DOMDocument::load(): Passing null to parameter #1 ($filename) of type string is deprecated
DEP:DOMDocument::loadHTMLFile(): Passing null to parameter #1 ($filename) of type string is deprecated
DEP:DOMNode::isSupported(): Passing null to parameter #1 ($feature) of type string is deprecated
DEP:DOMNode::isSupported(): Passing null to parameter #2 ($version) of type string is deprecated
DEP:DOMImplementation::hasFeature(): Passing null to parameter #1 ($feature) of type string is deprecated
DEP:DOMImplementation::hasFeature(): Passing null to parameter #2 ($version) of type string is deprecated
DEP:DOMElement::getAttributeNode(): Passing null to parameter #1 ($qualifiedName) of type string is deprecated
DEP:DOMElement::getAttributeNodeNS(): Passing null to parameter #2 ($localName) of type string is deprecated
DEP:DOMDocumentFragment::appendXML(): Passing null to parameter #1 ($data) of type string is deprecated
DEP:DOMImplementation::createDocument(): Passing null to parameter #2 ($qualifiedName) of type string is deprecated
