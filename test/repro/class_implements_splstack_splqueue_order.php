<?php
// #25797 — SplStack/SplQueue class_implements Serializable-first order
foreach (['SplStack', 'SplQueue'] as $c) {
    echo $c, '=', implode(',', class_implements($c)), "\n";
    $r = new ReflectionClass($c);
    echo $c, '_rf=', implode(',', $r->getInterfaceNames()), "\n";
}
