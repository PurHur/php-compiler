<?php
// #25823 — RecursiveRegex/CallbackFilter/TreeIterator class_implements Iterator-first
$classes = [
    'RecursiveRegexIterator',
    'RecursiveCallbackFilterIterator',
    'RecursiveTreeIterator',
];
foreach ($classes as $c) {
    echo $c, ':', implode(',', class_implements($c)), "\n";
}
$r = new ReflectionClass('RecursiveRegexIterator');
echo 'RecursiveRegexIterator refl:', implode(',', $r->getInterfaceNames()), "\n";
