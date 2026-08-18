<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::appendData null $data TypeError (#32376).
 * php-src ext/dom/characterdata.c Z_PARAM_STR.
 */
try {
    (new DOMDocument())->createTextNode('ab')->appendData(null);
    echo "noexception\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
