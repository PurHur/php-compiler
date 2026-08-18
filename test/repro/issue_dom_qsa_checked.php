<?php
// Dom ParentNode CSS :checked
// PROFILE=8.4 living Dom (php-src ext/dom/parentnode.c / lexbor; php-src
// ext/dom/tests/modern/css_selectors/pseudo_classes_checked.phpt).
$doc = Dom\XMLDocument::createFromString(
    '<html xmlns="http://www.w3.org/1999/xhtml">'
    .'<input type="checkbox" checked="checked" id="cb"/>'
    .'<input type="radio" checked="checked" id="rd"/>'
    .'<option selected="" id="opt"/>'
    .'<option xmlns="" selected="" id="optnn"/>'
    .'<input id="plain"/>'
    .'<input type="checkbox" id="uncb"/>'
    .'<input type="text" checked="checked" id="txt"/>'
    .'<input checked="checked" id="def"/>'
    .'<input type="CHECKBOX" checked="checked" id="cbup"/>'
    .'</html>'
);
foreach ([
    ':checked',
    'input:checked',
    'option:checked',
    ':is(:checked)',
    'input:not(:checked)',
    '#optnn:checked',
    '#txt:checked',
    '#def:checked',
    '#uncb:checked',
] as $sel) {
    try {
        $el = $doc->querySelector($sel);
        $all = $doc->querySelectorAll($sel);
        $ids = [];
        for ($i = 0; $i < $all->length; $i++) {
            $n = $all->item($i);
            $ids[] = $n->hasAttribute('id') && $n->getAttribute('id') !== ''
                ? $n->getAttribute('id')
                : $n->nodeName;
        }
        echo $sel, '=', $el !== null
            ? ($el->hasAttribute('id') && $el->getAttribute('id') !== '' ? $el->getAttribute('id') : $el->nodeName)
            : 'null',
            ' [', implode(',', $ids), "]\n";
    } catch (DOMException $ex) {
        echo $sel, '=EX:', $ex->getMessage(), "\n";
    }
}
$cb = $doc->querySelector('#cb');
$opt = $doc->querySelector('#opt');
$optnn = $doc->querySelector('#optnn');
$txt = $doc->querySelector('#txt');
try {
    echo 'matches_cb=', $cb->matches(':checked') ? 'yes' : 'no', "\n";
    echo 'matches_opt=', $opt->matches('option:checked') ? 'yes' : 'no', "\n";
    echo 'matches_optnn=', $optnn->matches(':checked') ? 'yes' : 'no', "\n";
    echo 'matches_txt=', $txt->matches(':checked') ? 'yes' : 'no', "\n";
    $closest = $opt->closest(':checked');
    echo 'closest=', $closest !== null ? $closest->getAttribute('id') : 'null', "\n";
} catch (DOMException $ex) {
    echo 'matches=EX:', $ex->getMessage(), "\n";
}
$loose = $doc->createElementNS('http://www.w3.org/1999/xhtml', 'option');
$loose->setAttribute('selected', '');
echo 'loose_xhtml_opt=', $loose->matches(':checked') ? 'yes' : 'no', "\n";
$looseNn = $doc->createElement('option');
$looseNn->setAttribute('selected', '');
echo 'loose_nons_opt=', $looseNn->matches(':checked') ? 'yes' : 'no', "\n";

$html = Dom\HTMLDocument::createFromString(
    '<html><body><form><input type="checkbox" checked id="hcb">'
    .'<input type="radio" checked id="hrd">'
    .'<select><option selected id="hopt">x</option></select></form></body></html>',
    LIBXML_NOERROR
);
$hall = $html->querySelectorAll(':checked');
$hids = [];
for ($i = 0; $i < $hall->length; $i++) {
    $hids[] = $hall->item($i)->id;
}
echo 'html_checked=[', implode(',', $hids), "]\n";

foreach ([':checked()', ':checked(1)'] as $bad) {
    try {
        $el = $doc->querySelector($bad);
        echo "bad[$bad]=", $el === null ? 'null' : 'hit', "\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
