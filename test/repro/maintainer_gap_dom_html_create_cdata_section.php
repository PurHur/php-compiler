<?php

declare(strict_types=1);

/**
 * #21064 — Dom\HTMLDocument::createCDATASection must throw Dom\DOMException NOT_SUPPORTED_ERR.
 * Dom\XMLDocument::createCDATASection remains allowed.
 */
$html = Dom\HTMLDocument::createFromString('<html><body></body></html>', LIBXML_NOERROR);
try {
    $cd = $html->createCDATASection('x');
    echo 'html_ok=', get_class($cd), "\n";
} catch (Throwable $e) {
    $cls = ($e instanceof Dom\DOMException) ? 'Dom\\DOMException' : get_class($e);
    echo 'html=', $cls, ' msg=', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

$xml = Dom\XMLDocument::createEmpty();
echo 'xml=', get_class($xml->createCDATASection('x<y>')), "\n";
