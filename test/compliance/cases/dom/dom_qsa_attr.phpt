--TEST--
stdlib Dom ParentNode CSS attribute selectors (#32089, ext/dom/parentnode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><div id="d" class="box" data-x="1"><p id="p" class="x y">t</p><span id="s" hidden></span></div><p id="p2" lang="en-US">z</p></body></html>'
);
foreach ([
    '[hidden]',
    '[id="p"]',
    '[id=p]',
    '[class~="y"]',
    '[id^="p"]',
    '[id$="2"]',
    '[id*="p"]',
    '[lang|="en"]',
    'span[hidden]',
    'div[class="box"]',
    '[data-x="1"]',
    'p[class~="x"]',
    'div > [hidden]',
    'p[id] + span',
    '[id="P" i]',
    '[class="x y"]',
    'div[id]',
] as $sel) {
    try {
        $el = $doc->querySelector($sel);
        echo $sel, '=', $el !== null ? $el->id : 'null', "\n";
    } catch (DOMException $ex) {
        echo $sel, '=EX:', $ex->getMessage(), "\n";
    }
}
echo 'qsa_id=', $doc->querySelectorAll('[id]')->length, "\n";
$s = $doc->getElementById('s');
$p = $doc->getElementById('p');
echo 'matches_hidden=', $s->matches('[hidden]') ? 'yes' : 'no', "\n";
echo 'matches_p_hidden=', $p->matches('[hidden]') ? 'yes' : 'no', "\n";
echo 'closest=', $s->closest('[class]')->id, "\n";
foreach (['[', '[]', '[=x]', '[attr=]'] as $bad) {
    try {
        $doc->querySelector($bad);
        echo "bad[$bad]=ok\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
?>
--EXPECT--
[hidden]=s
[id="p"]=p
[id=p]=p
[class~="y"]=p
[id^="p"]=p
[id$="2"]=p2
[id*="p"]=p
[lang|="en"]=p2
span[hidden]=s
div[class="box"]=d
[data-x="1"]=d
p[class~="x"]=p
div > [hidden]=s
p[id] + span=s
[id="P" i]=p
[class="x y"]=p
div[id]=d
qsa_id=4
matches_hidden=yes
matches_p_hidden=no
closest=d
bad[[]=SyntaxError
bad[[]]=SyntaxError
bad[[=x]]=SyntaxError
bad[[attr=]]=SyntaxError
