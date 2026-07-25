<?php

declare(strict_types=1);

/**
 * #22773 — Dom\Element::$innerHTML on HTMLDocument uses HTML serializer
 * (empty non-void → <i></i>; void → <br> / <img …>), matching saveHtml().
 * XMLDocument keeps XML empty-element form.
 */
if (!class_exists('Dom\\HTMLDocument')) {
    fwrite(STDERR, "skip: Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4\n");
    exit(2);
}

$html = Dom\HTMLDocument::createFromString(
    '<!doctype html><p id="p"><i></i><br><img src="x"></p>',
    LIBXML_NOERROR
);
$p = $html->getElementById('p');
if (null === $p) {
    fwrite(STDERR, "missing p\n");
    exit(1);
}

$expected = '<i></i><br><img src="x">';
$inner = $p->innerHTML;
echo 'html_inner=', var_export($inner, true), "\n";
echo 'html_ok=', (int) ($inner === $expected), "\n";

$xml = Dom\XMLDocument::createFromString('<root><i/><br/><img src="x"/></root>');
$root = $xml->documentElement;
$xmlInner = $root->innerHTML;
echo 'xml_inner=', var_export($xmlInner, true), "\n";
echo 'xml_has_slash=', (int) (str_contains($xmlInner, '<i/>') || str_contains($xmlInner, '<i />')), "\n";

if ($inner !== $expected) {
    exit(1);
}
echo "ok\n";
