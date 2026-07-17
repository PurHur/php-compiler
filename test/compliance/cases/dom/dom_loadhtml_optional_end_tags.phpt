--TEST--
dom DOMDocument::loadHTML() optional end tags do not collapse the tree (#20247)
--FILE--
<?php
$cases = [
    'unclosed-p-void' => ['<p>hi<br><img src="x">', 'p', 1],
    'p-p' => ['<p>a<p>b', 'p', 2],
    'li-li' => ['<ul><li>a<li>b</ul>', 'li', 2],
    'td-td' => ['<table><tr><td>a<td>b</tr></table>', 'td', 2],
    'unclosed-p-in-body' => ['<body><p>hi</body>', 'p', 1],
];
foreach ($cases as $name => [$html, $tag, $min]) {
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    $n = $doc->getElementsByTagName($tag)->length;
    echo $name, '=', $n >= $min ? 'ok' : 'fail:'.$n, "\n";
}
$doc = new DOMDocument();
$doc->loadHTML('<p>hi<br><img src="x">');
echo 'br=', $doc->getElementsByTagName('br')->length, "\n";
echo 'img=', $doc->getElementsByTagName('img')->length, "\n";
--EXPECT--
unclosed-p-void=ok
p-p=ok
li-li=ok
td-td=ok
unclosed-p-in-body=ok
br=1
img=1
