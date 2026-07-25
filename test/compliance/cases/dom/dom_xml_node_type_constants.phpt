--TEST--
DOM XML_*_NODE + DOM_PHP_ERR globals match php-src (issue #23138, ext/dom/php_dom.c)
--FILE--
<?php
$expect = [
    'XML_ELEMENT_NODE' => 1,
    'XML_ATTRIBUTE_NODE' => 2,
    'XML_TEXT_NODE' => 3,
    'XML_CDATA_SECTION_NODE' => 4,
    'XML_ENTITY_REF_NODE' => 5,
    'XML_ENTITY_NODE' => 6,
    'XML_PI_NODE' => 7,
    'XML_COMMENT_NODE' => 8,
    'XML_DOCUMENT_NODE' => 9,
    'XML_DOCUMENT_TYPE_NODE' => 10,
    'XML_DOCUMENT_FRAG_NODE' => 11,
    'XML_NOTATION_NODE' => 12,
    'XML_HTML_DOCUMENT_NODE' => 13,
    'XML_DTD_NODE' => 14,
    'XML_ELEMENT_DECL_NODE' => 15,
    'XML_ATTRIBUTE_DECL_NODE' => 16,
    'XML_ENTITY_DECL_NODE' => 17,
    'XML_NAMESPACE_DECL_NODE' => 18,
    'XML_LOCAL_NAMESPACE' => 18,
    'DOM_PHP_ERR' => 0,
];
// Bare names (not only constant()) so AOT matches VM (#21137 / #23138).
$got = [
    'XML_ELEMENT_NODE' => defined('XML_ELEMENT_NODE') ? XML_ELEMENT_NODE : null,
    'XML_ATTRIBUTE_NODE' => defined('XML_ATTRIBUTE_NODE') ? XML_ATTRIBUTE_NODE : null,
    'XML_TEXT_NODE' => defined('XML_TEXT_NODE') ? XML_TEXT_NODE : null,
    'XML_CDATA_SECTION_NODE' => defined('XML_CDATA_SECTION_NODE') ? XML_CDATA_SECTION_NODE : null,
    'XML_ENTITY_REF_NODE' => defined('XML_ENTITY_REF_NODE') ? XML_ENTITY_REF_NODE : null,
    'XML_ENTITY_NODE' => defined('XML_ENTITY_NODE') ? XML_ENTITY_NODE : null,
    'XML_PI_NODE' => defined('XML_PI_NODE') ? XML_PI_NODE : null,
    'XML_COMMENT_NODE' => defined('XML_COMMENT_NODE') ? XML_COMMENT_NODE : null,
    'XML_DOCUMENT_NODE' => defined('XML_DOCUMENT_NODE') ? XML_DOCUMENT_NODE : null,
    'XML_DOCUMENT_TYPE_NODE' => defined('XML_DOCUMENT_TYPE_NODE') ? XML_DOCUMENT_TYPE_NODE : null,
    'XML_DOCUMENT_FRAG_NODE' => defined('XML_DOCUMENT_FRAG_NODE') ? XML_DOCUMENT_FRAG_NODE : null,
    'XML_NOTATION_NODE' => defined('XML_NOTATION_NODE') ? XML_NOTATION_NODE : null,
    'XML_HTML_DOCUMENT_NODE' => defined('XML_HTML_DOCUMENT_NODE') ? XML_HTML_DOCUMENT_NODE : null,
    'XML_DTD_NODE' => defined('XML_DTD_NODE') ? XML_DTD_NODE : null,
    'XML_ELEMENT_DECL_NODE' => defined('XML_ELEMENT_DECL_NODE') ? XML_ELEMENT_DECL_NODE : null,
    'XML_ATTRIBUTE_DECL_NODE' => defined('XML_ATTRIBUTE_DECL_NODE') ? XML_ATTRIBUTE_DECL_NODE : null,
    'XML_ENTITY_DECL_NODE' => defined('XML_ENTITY_DECL_NODE') ? XML_ENTITY_DECL_NODE : null,
    'XML_NAMESPACE_DECL_NODE' => defined('XML_NAMESPACE_DECL_NODE') ? XML_NAMESPACE_DECL_NODE : null,
    'XML_LOCAL_NAMESPACE' => defined('XML_LOCAL_NAMESPACE') ? XML_LOCAL_NAMESPACE : null,
    'DOM_PHP_ERR' => defined('DOM_PHP_ERR') ? DOM_PHP_ERR : null,
];
$ok = 1;
foreach ($expect as $name => $want) {
    if (null === $got[$name] || $got[$name] !== $want) {
        $ok = 0;
        echo 'bad=', $name, ' got=', null === $got[$name] ? 'MISSING' : (string) $got[$name], "\n";
    }
}
$dom = new DOMDocument();
$dom->loadXML('<root/>');
$el = $dom->documentElement;
if (XML_ELEMENT_NODE !== $el->nodeType) {
    $ok = 0;
    echo 'bad=nodeType=', $el->nodeType, "\n";
}
echo 'ok=', $ok, "\n";
echo 'element=', XML_ELEMENT_NODE, "\n";
echo 'pi=', XML_PI_NODE, "\n";
echo 'php_err=', DOM_PHP_ERR, "\n";
echo 'index_err=', DOM_INDEX_SIZE_ERR, "\n";
?>
--EXPECT--
ok=1
element=1
pi=7
php_err=0
index_err=1
