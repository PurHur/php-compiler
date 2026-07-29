--TEST--
DOMDocument::xinclude() text/xml include + missing href (#20403, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);
$dir = sys_get_temp_dir() . '/dom_xinclude_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir);
}
$part = $dir . '/part.xml';
file_put_contents($part, "hi\n");
$child = $dir . '/child.xml';
file_put_contents($child, '<child>ok</child>');
$missing = $dir . '/missing.xml';

$warnings = [];
set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$doc4 = new DOMDocument();
$missXml = '<r xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="' . $missing . '" parse="text"/></r>';
$doc4->loadXML($missXml);
$n4 = $doc4->xinclude();
restore_error_handler();
echo 'miss_n=', var_export($n4, true), "\n";
echo 'warns=', (string) count($warnings), "\n";
foreach ($warnings as $w) {
    if (str_contains($w, 'I/O warning')) {
        echo "io\n";
    } elseif (str_contains($w, 'no fallback was found')) {
        echo "nofallback\n";
    } else {
        echo $w, "\n";
    }
}

$textXml = '<r xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="' . $part . '" parse="text"/></r>';
$doc = new DOMDocument();
$doc->loadXML($textXml);
$n = $doc->xinclude();
$out = $doc->saveXML();
echo 'text_n=', var_export($n, true), ' has=', (str_contains($out, 'hi') ? 'yes' : 'no'), "\n";

$xmlXml = '<r xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="' . $child . '" parse="xml"/></r>';
$doc2 = new DOMDocument();
$doc2->loadXML($xmlXml);
$n2 = $doc2->xinclude();
$out2 = $doc2->saveXML();
// libxml may stamp xml:base on the included root (#24775) — match content, not exact attrs.
$xmlHas = str_contains($out2, '<child') && str_contains($out2, '>ok</child>');
echo 'xml_n=', var_export($n2, true), ' has=', ($xmlHas ? 'yes' : 'no'), "\n";

$doc3 = new DOMDocument();
$doc3->loadXML('<r/>');
echo 'none_n=', var_export($doc3->xinclude(), true), "\n";

$fbXml = '<r xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="' . $missing . '" parse="text"><xi:fallback>fallback</xi:fallback></xi:include></r>';
$doc5 = new DOMDocument();
$doc5->loadXML($fbXml);
$n5 = @$doc5->xinclude();
$out5 = $doc5->saveXML();
echo 'fb_n=', var_export($n5, true), ' has=', (str_contains($out5, 'fallback') ? 'yes' : 'no'), "\n";

@unlink($part);
@unlink($child);
@rmdir($dir);
--EXPECT--
miss_n=-1
warns=2
io
nofallback
text_n=1 has=yes
xml_n=1 has=yes
none_n=false
fb_n=1 has=yes
