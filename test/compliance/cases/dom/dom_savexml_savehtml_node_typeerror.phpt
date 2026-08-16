--TEST--
ext/dom DOMDocument::saveXML/saveHTML int $node TypeError ?DOMNode (#31396, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
foreach ([
    'saveXML_int' => static function () use ($d) { $d->saveXML(1); },
    'saveXML_libxml' => static function () use ($d) { $d->saveXML(LIBXML_NOEMPTYTAG); },
    'saveHTML_int' => static function () use ($d) { $d->saveHTML(1); },
    'saveXML_string' => static function () use ($d) { $d->saveXML('x'); },
] as $label => $fn) {
    try {
        $fn();
        echo $label, "=accepted\n";
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$ok = $d->saveXML(null, LIBXML_NOEMPTYTAG);
echo 'null_options=', (false !== strpos($ok, '<a></a>') ? 'ok' : 'bad'), "\n";
?>
--EXPECT--
saveXML_int=TypeError:DOMDocument::saveXML(): Argument #1 ($node) must be of type ?DOMNode, int given
saveXML_libxml=TypeError:DOMDocument::saveXML(): Argument #1 ($node) must be of type ?DOMNode, int given
saveHTML_int=TypeError:DOMDocument::saveHTML(): Argument #1 ($node) must be of type ?DOMNode, int given
saveXML_string=TypeError:DOMDocument::saveXML(): Argument #1 ($node) must be of type ?DOMNode, string given
null_options=ok
