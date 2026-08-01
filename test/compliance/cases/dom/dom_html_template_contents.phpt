--TEST--
HTML <template> contents live in DocumentFragment — firstChild null (php-src template_manual.phpt; #26034)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#26034)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>');
$template = $html->createElement('template');
$html->body->appendChild($template);
$template->innerHTML = '<p>hello</template></p>';
echo 'innerHTML=', var_export($template->innerHTML, true), "\n";
echo 'firstChild=', $template->firstChild === null ? 'NULL' : $template->firstChild->nodeName, "\n";
echo 'childElementCount=', $template->childElementCount, "\n";

$parsed = Dom\HTMLDocument::createFromString('<template><p>x</p></template>', LIBXML_NOERROR);
$t = $parsed->getElementsByTagName('template')->item(0);
echo 'fromString firstChild=', $t->firstChild === null ? 'NULL' : $t->firstChild->nodeName, "\n";
echo 'fromString childElementCount=', $t->childElementCount, "\n";
echo 'fromString innerHTML=', var_export($t->innerHTML, true), "\n";
echo 'saveHasMarkup=', str_contains($parsed->saveHtml(), '<p>x</p>') ? 'yes' : 'no', "\n";

$noNs = Dom\HTMLDocument::createFromString(
    '<template><p>x</p></template>',
    LIBXML_NOERROR | Dom\HTML_NO_DEFAULT_NS
);
$t2 = $noNs->getElementsByTagName('template')->item(0);
echo 'no_ns firstChild=', $t2->firstChild === null ? 'NULL' : $t2->firstChild->nodeName, "\n";
?>
--EXPECT--
innerHTML='<p>hello</p>'
firstChild=NULL
childElementCount=0
fromString firstChild=NULL
fromString childElementCount=0
fromString innerHTML='<p>x</p>'
saveHasMarkup=yes
no_ns firstChild=p
