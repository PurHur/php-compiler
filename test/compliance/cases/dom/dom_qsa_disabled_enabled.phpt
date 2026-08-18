--TEST--
stdlib Dom ParentNode CSS :disabled/:enabled (#32235, ext/dom/parentnode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\XMLDocument::createFromString(
    '<html xmlns="http://www.w3.org/1999/xhtml">'
    .'<button id="btn"/>'
    .'<button xmlns="" disabled="disabled" id="btnnn"/>'
    .'<button disabled="disabled" id="btnd"/>'
    .'<input disabled="disabled" id="inp"/>'
    .'<select disabled="disabled" id="sel"/>'
    .'<textarea disabled="disabled" id="ta"/>'
    .'<optgroup disabled="disabled" id="og"/>'
    .'<option disabled="disabled" id="opt"/>'
    .'<fieldset disabled="disabled" id="fs0"/>'
    .'<fieldset disabled="disabled" id="fs1p"><fieldset id="fs1"/></fieldset>'
    .'<fieldset disabled="disabled" id="fsleg">'
    .'<!-- foo -->'
    .'<legend><div><fieldset id="fs2"/></div></legend>'
    .'<div><fieldset id="fs3"/></div>'
    .'</fieldset>'
    .'</html>'
);
foreach ([
    ':disabled',
    ':enabled',
    'button:disabled',
    'button:enabled',
    ':is(:disabled)',
    'button:not(:disabled)',
    '#btnnn:disabled',
    '#fs1:disabled',
    '#fs2:disabled',
    '#fs3:disabled',
    '#opt:disabled',
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
$btnd = $doc->querySelector('#btnd');
$btn = $doc->querySelector('#btn');
$fs3 = $doc->querySelector('#fs3');
$fs1 = $doc->querySelector('#fs1');
$opt = $doc->querySelector('#opt');
try {
    echo 'matches_btnd=', $btnd->matches(':disabled') ? 'yes' : 'no', "\n";
    echo 'matches_btn=', $btn->matches(':disabled') ? 'yes' : 'no', "\n";
    echo 'matches_fs3=', $fs3->matches(':disabled') ? 'yes' : 'no', "\n";
    echo 'matches_fs1=', $fs1->matches(':disabled') ? 'yes' : 'no', "\n";
    echo 'matches_opt=', $opt->matches(':disabled') ? 'yes' : 'no', "\n";
    echo 'enabled_btn=', $btn->matches(':enabled') ? 'yes' : 'no', "\n";
    echo 'enabled_btnd=', $btnd->matches(':enabled') ? 'yes' : 'no', "\n";
    $closest = $fs3->closest(':disabled');
    echo 'closest_fs3=', $closest !== null ? $closest->getAttribute('id') : 'null', "\n";
} catch (DOMException $ex) {
    echo 'matches=EX:', $ex->getMessage(), "\n";
}

$html = Dom\HTMLDocument::createFromString(
    '<html><body><form>'
    .'<button disabled id="hbtnd">x</button>'
    .'<button id="hbtn">y</button>'
    .'<input disabled id="hinp">'
    .'<fieldset disabled id="hfs"><input id="hinner"></fieldset>'
    .'</form></body></html>',
    LIBXML_NOERROR
);
$hall = $html->querySelectorAll(':disabled');
$hids = [];
for ($i = 0; $i < $hall->length; $i++) {
    $hids[] = $hall->item($i)->id;
}
echo 'html_disabled=[', implode(',', $hids), "]\n";

foreach ([':disabled()', ':enabled(1)'] as $bad) {
    try {
        $el = $doc->querySelector($bad);
        echo "bad[$bad]=", $el === null ? 'null' : 'hit', "\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
?>
--EXPECT--
:disabled=btnd [btnd,inp,sel,ta,og,fs0,fs1p,fsleg,fs3]
:enabled=html [html,btn,btnnn,opt,fs1,legend,div,fs2,div]
button:disabled=btnd [btnd]
button:enabled=btn [btn,btnnn]
:is(:disabled)=btnd [btnd,inp,sel,ta,og,fs0,fs1p,fsleg,fs3]
button:not(:disabled)=btn [btn,btnnn]
#btnnn:disabled=null []
#fs1:disabled=null []
#fs2:disabled=null []
#fs3:disabled=fs3 [fs3]
#opt:disabled=null []
matches_btnd=yes
matches_btn=no
matches_fs3=yes
matches_fs1=no
matches_opt=no
enabled_btn=yes
enabled_btnd=no
closest_fs3=fs3
html_disabled=[hbtnd,hinp,hfs]
bad[:disabled()]=SyntaxError
bad[:enabled(1)]=SyntaxError
