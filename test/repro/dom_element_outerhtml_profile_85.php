<?php
/**
 * #22482 companion — Dom\Element::$outerHTML live get/set on PROFILE=8.5.
 */
$html = Dom\HTMLDocument::createFromString(
    '<div id="d" class="a"><p>Hi</p></div>',
    LIBXML_NOERROR
);
$el = $html->getElementById('d');
echo 'isset_outer=', var_export(isset($el->outerHTML), true), PHP_EOL;
echo 'outer=', $el->outerHTML, PHP_EOL;
$el->outerHTML = '<span id="s">x</span>';
$s = $html->getElementById('s');
echo 'replaced=', ($s !== null ? $s->outerHTML : 'NULL'), PHP_EOL;
echo 'old_gone=', ($html->getElementById('d') === null ? 'yes' : 'no'), PHP_EOL;
