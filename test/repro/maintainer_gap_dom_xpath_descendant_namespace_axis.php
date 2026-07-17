<?php
/**
 * #20170 — DOMXPath descendant/location-path namespace axis (ext/dom/xpath.c).
 *
 * Zend: //namespace::* and /r/namespace::a return DOMNameSpaceNode lists.
 * Pre-fix VM: DOMException Undefined namespace prefix.
 */
$xml = '<r xmlns:a="urn:a"><c xmlns:b="urn:b"/></r>';
$dom = new DOMDocument();
$dom->loadXML($xml);
$xp = new DOMXPath($dom);
$el = $dom->documentElement->firstChild;
foreach (['namespace::*', '//namespace::*', '//c/namespace::*', '/r/namespace::a'] as $q) {
    try {
        $list = $xp->query($q, $el);
        echo $q, ' => ', false === $list ? 'false' : (string) $list->length, "\n";
    } catch (Throwable $e) {
        echo $q, ' => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
