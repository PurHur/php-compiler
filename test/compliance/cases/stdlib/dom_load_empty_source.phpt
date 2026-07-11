--TEST--
DOMDocument::loadHTML()/loadXML() empty $source throws ValueError (#17616, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();

try {
    $doc->loadHTML('');
} catch (ValueError $e) {
    echo 'loadHTML: ', $e->getMessage(), "\n";
}

try {
    $doc->loadXML('');
} catch (ValueError $e) {
    echo 'loadXML: ', $e->getMessage(), "\n";
}
--EXPECT--
loadHTML: DOMDocument::loadHTML(): Argument #1 ($source) must not be empty
loadXML: DOMDocument::loadXML(): Argument #1 ($source) must not be empty
