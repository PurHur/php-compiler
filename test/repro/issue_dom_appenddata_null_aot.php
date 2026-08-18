<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::appendData(null) is TypeError under strict_types (#32376).
 * php-src ext/dom/characterdata.c Z_PARAM_STR.
 */
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
try {
    $text->appendData(null);
    echo "noexception\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
