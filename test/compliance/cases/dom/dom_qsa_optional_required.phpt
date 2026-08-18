--TEST--
stdlib Dom ParentNode CSS :optional/:required (#32257, ext/dom/parentnode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\XMLDocument::createFromString(
    '<html xmlns="http://www.w3.org/1999/xhtml">'
    .'<input type="checkbox" required="required" id="cbr"/>'
    .'<select required="required" id="selr"/>'
    .'<textarea required="" id="tar"/>'
    .'<input type="checkbox" id="cb"/>'
    .'<select id="sel"/>'
    .'<textarea id="ta"/>'
    .'<input xmlns="" id="inn"/>'
    .'<input xmlns="" required="" id="innr"/>'
    .'<button required="required" id="btn"/>'
    .'</html>'
);
foreach ([
    ':required',
    ':optional',
    'input:required',
    'input:optional',
    ':is(:required)',
    'input:not(:required)',
    '#inn:optional',
    '#innr:required',
    '#btn:required',
    '#tar:required',
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
$cbr = $doc->querySelector('#cbr');
$cb = $doc->querySelector('#cb');
$inn = $doc->querySelector('#inn');
$innr = $doc->querySelector('#innr');
$btn = $doc->querySelector('#btn');
$tar = $doc->querySelector('#tar');
try {
    echo 'matches_cbr=', $cbr->matches(':required') ? 'yes' : 'no', "\n";
    echo 'matches_cb=', $cb->matches(':optional') ? 'yes' : 'no', "\n";
    echo 'matches_inn=', $inn->matches(':optional') ? 'yes' : 'no', "\n";
    echo 'matches_innr=', $innr->matches(':required') ? 'yes' : 'no', "\n";
    echo 'matches_btn=', $btn->matches(':required') ? 'yes' : 'no', "\n";
    echo 'matches_tar=', $tar->matches(':required') ? 'yes' : 'no', "\n";
    echo 'optional_cbr=', $cbr->matches(':optional') ? 'yes' : 'no', "\n";
    $closest = $tar->closest(':required');
    echo 'closest_tar=', $closest !== null ? $closest->getAttribute('id') : 'null', "\n";
} catch (DOMException $ex) {
    echo 'matches=EX:', $ex->getMessage(), "\n";
}

$html = Dom\HTMLDocument::createFromString(
    '<html><body><form>'
    .'<input required id="hinpr">'
    .'<input id="hinp">'
    .'<select required id="hselr"></select>'
    .'<textarea id="hta"></textarea>'
    .'<button required id="hbtn">x</button>'
    .'</form></body></html>',
    LIBXML_NOERROR
);
$hall = $html->querySelectorAll(':required');
$hids = [];
for ($i = 0; $i < $hall->length; $i++) {
    $hids[] = $hall->item($i)->id;
}
echo 'html_required=[', implode(',', $hids), "]\n";
$hallo = $html->querySelectorAll(':optional');
$hoid = [];
for ($i = 0; $i < $hallo->length; $i++) {
    $hoid[] = $hallo->item($i)->id;
}
echo 'html_optional=[', implode(',', $hoid), "]\n";

foreach ([':required()', ':optional(1)'] as $bad) {
    try {
        $el = $doc->querySelector($bad);
        echo "bad[$bad]=", $el === null ? 'null' : 'hit', "\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
?>
--EXPECT--
:required=cbr [cbr,selr,tar]
:optional=cb [cb,sel,ta]
input:required=cbr [cbr]
input:optional=cb [cb]
:is(:required)=cbr [cbr,selr,tar]
input:not(:required)=cb [cb,inn,innr]
#inn:optional=null []
#innr:required=null []
#btn:required=null []
#tar:required=tar [tar]
matches_cbr=yes
matches_cb=yes
matches_inn=no
matches_innr=no
matches_btn=no
matches_tar=yes
optional_cbr=no
closest_tar=tar
html_required=[hinpr,hselr]
html_optional=[hinp,hta]
bad[:required()]=SyntaxError
bad[:optional(1)]=SyntaxError
