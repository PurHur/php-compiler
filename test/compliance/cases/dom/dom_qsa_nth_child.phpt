--TEST--
stdlib Dom ParentNode CSS nth/typed structural pseudo-classes (#32108, ext/dom/parentnode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\XMLDocument::createFromString(
    '<container><h2 id="h1">1</h2><h2 id="h2">2</h2><h2 id="h3">3</h2><h2 id="h4">4</h2><h2 id="h5">5</h2></container>'
);
foreach ([
    'h2:nth-child(1)',
    'h2:nth-child(2)',
    'h2:nth-child(odd)',
    'h2:nth-child(even)',
    'h2:nth-child(2n)',
    'h2:nth-child(2n+1)',
    'h2:nth-child(2n + 1)',
    'h2:nth-child(3n-2)',
    'h2:nth-last-child(1)',
    'h2:nth-last-child(2)',
    'h2:nth-of-type(2)',
    'h2:nth-of-type(n+2)',
    'h2:first-of-type',
    'h2:last-of-type',
    'h2:nth-of-type(n+2):nth-last-of-type(n+2)',
] as $sel) {
    try {
        $el = $doc->querySelector($sel);
        $all = $doc->querySelectorAll($sel);
        $ids = [];
        for ($i = 0; $i < $all->length; $i++) {
            $ids[] = $all->item($i)->getAttribute('id');
        }
        echo $sel, '=', $el !== null ? $el->getAttribute('id') : 'null', ' [', implode(',', $ids), "]\n";
    } catch (DOMException $ex) {
        echo $sel, '=EX:', $ex->getMessage(), "\n";
    }
}
$h2 = $doc->querySelector('h2:nth-child(2)');
echo 'matches_nth2=', $h2->matches('h2:nth-child(2)') ? 'yes' : 'no', "\n";
$closest = $h2->closest('container:nth-child(1), h2:nth-child(2)');
echo 'closest=', $closest !== null ? $closest->getAttribute('id') : 'null', "\n";
$doc2 = Dom\XMLDocument::createFromString('<container>x<p id="p">y</p></container>');
$p = $doc2->querySelector('p');
echo 'text_before_first=', $p->matches(':first-child') ? 'yes' : 'no', "\n";
echo 'text_before_nth1=', $p->matches(':nth-child(1)') ? 'yes' : 'no', "\n";
foreach ([':nth-child()', ':nth-child(foo)', ':nth-child(2n+)', 'p:bar'] as $bad) {
    try {
        $doc->querySelector($bad);
        echo "bad[$bad]=ok\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
?>
--EXPECT--
h2:nth-child(1)=h1 [h1]
h2:nth-child(2)=h2 [h2]
h2:nth-child(odd)=h1 [h1,h3,h5]
h2:nth-child(even)=h2 [h2,h4]
h2:nth-child(2n)=h2 [h2,h4]
h2:nth-child(2n+1)=h1 [h1,h3,h5]
h2:nth-child(2n + 1)=h1 [h1,h3,h5]
h2:nth-child(3n-2)=h1 [h1,h4]
h2:nth-last-child(1)=h5 [h5]
h2:nth-last-child(2)=h4 [h4]
h2:nth-of-type(2)=h2 [h2]
h2:nth-of-type(n+2)=h2 [h2,h3,h4,h5]
h2:first-of-type=h1 [h1]
h2:last-of-type=h5 [h5]
h2:nth-of-type(n+2):nth-last-of-type(n+2)=h2 [h2,h3,h4]
matches_nth2=yes
closest=h2
text_before_first=yes
text_before_nth1=yes
bad[:nth-child()]=SyntaxError
bad[:nth-child(foo)]=SyntaxError
bad[:nth-child(2n+)]=SyntaxError
bad[p:bar]=SyntaxError
