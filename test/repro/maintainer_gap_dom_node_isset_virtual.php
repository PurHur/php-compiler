<?php
/**
 * Dom\Node isset(textContent/ownerDocument/isConnected/baseURI) while readable (#21053).
 * php-src: ext/dom/node.c / php_dom.stub.php — virtual props report isset true when non-null.
 */
$html = '<html><body><div id="x">hi</div></body></html>';
$doc = Dom\HTMLDocument::createFromString($html);
$el = $doc->getElementById('x');
echo 'textContent=', var_export($el->textContent, true), "\n";
echo 'isset_textContent=', var_export(isset($el->textContent), true), "\n";
echo 'ownerDocument=', get_class($el->ownerDocument), "\n";
echo 'isset_ownerDocument=', var_export(isset($el->ownerDocument), true), "\n";
echo 'isConnected=', var_export($el->isConnected, true), "\n";
echo 'isset_isConnected=', var_export(isset($el->isConnected), true), "\n";
echo 'baseURI=', var_export($el->baseURI, true), "\n";
echo 'isset_baseURI=', var_export(isset($el->baseURI), true), "\n";
