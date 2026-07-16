<?php
$d = new DOMDocument();
$d->loadXML('<r><a>1</a><a>2</a><b>hello</b></r>');
$x = new DOMXPath($d);
foreach (['count(//a)', 'string(//a[1])', 'string(//a[2])', 'number(//a[2])', 'string(//b)', 'string(//a)'] as $e) {
    echo $e, ' => ', var_export($x->evaluate($e), true), "\n";
}
