--TEST--
Dom\XPath + Dom\NodeList on HTMLDocument/XMLDocument (#20757)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20757)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo class_exists('Dom\\XPath') ? "xpath\n" : "no_xpath\n";
echo class_exists('Dom\\NodeList') ? "nodelist\n" : "no_nodelist\n";

$doc = Dom\HTMLDocument::createFromString(
    '<html><body><p id="a">hi</p><p class="b">yo</p></body></html>'
);
$xp = new Dom\XPath($doc);
echo get_class($xp), "\n";
$list = $xp->query('//p');
echo get_class($list), "\n";
echo ($list instanceof Dom\NodeList) ? "isa\n" : "not\n";
echo 'len=', $list->length, "\n";
echo 'item=', $list->item(0)->tagName, "\n";
echo 'count=', $xp->evaluate('count(//p)'), "\n";

$xml = Dom\XMLDocument::createFromString('<r xmlns:p="urn:x"><p:a>1</p:a></r>');
$xp2 = new Dom\XPath($xml);
$xp2->registerNamespace('q', 'urn:x');
echo 'ns=', $xp2->query('//q:a')->length, "\n";

try {
    new Dom\XPath(new DOMDocument());
    echo "legacy_ok\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'Document') ? "legacy_type\n" : "legacy_other\n";
}

$legacy = new DOMDocument();
$legacy->loadXML('<r><a/></r>');
echo 'legacy_list=', get_class((new DOMXPath($legacy))->query('//a')), "\n";
--EXPECT--
xpath
nodelist
Dom\XPath
Dom\NodeList
isa
len=2
item=p
count=2
ns=1
legacy_type
legacy_list=DOMNodeList
