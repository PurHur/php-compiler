<?php
/**
 * Repro #31707 — DOMNodeList / DOMNamedNodeMap / DOMCharacterData::$length read-only
 * (php-src ext/dom/nodelist.c, nodemap.c, characterdata.c).
 */
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r a="1"><a/></r>');

$list = $d->getElementsByTagName('*');
$beforeList = $list->length;
try {
    $list->length = 99;
    echo "list_write_ok length={$list->length}\n";
} catch (Throwable $e) {
    echo $e->getMessage(), " length_after={$list->length} before={$beforeList}\n";
}

$map = $d->documentElement->attributes;
$beforeMap = $map->length;
try {
    $map->length = 99;
    echo "map_write_ok length={$map->length}\n";
} catch (Throwable $e) {
    echo $e->getMessage(), " length_after={$map->length} before={$beforeMap}\n";
}

$cn = $d->documentElement->childNodes;
try {
    $cn->length = 5;
    echo "cn_write_ok length={$cn->length}\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

$text = $d->createTextNode('hi');
try {
    $text->length = 9;
    echo "text_write_ok length={$text->length}\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
