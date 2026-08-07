--TEST--
FilterIterator accept() public Reflection visibility (#28560, ext/spl/spl_iterators.stub.php)
--FILE--
<?php
$classes = [
    'FilterIterator',
    'RecursiveFilterIterator',
    'CallbackFilterIterator',
    'RegexIterator',
    'ParentIterator',
    'RecursiveCallbackFilterIterator',
    'RecursiveRegexIterator',
];
foreach ($classes as $c) {
    $r = new ReflectionMethod($c, 'accept');
    echo $c, ':pub=', $r->isPublic() ? 'y' : 'n',
        ' abs=', $r->isAbstract() ? 'y' : 'n',
        ' gcm=', in_array('accept', get_class_methods($c) ?: [], true) ? 'y' : 'n',
        "\n";
}
?>
--EXPECT--
FilterIterator:pub=y abs=y gcm=y
RecursiveFilterIterator:pub=y abs=y gcm=y
CallbackFilterIterator:pub=y abs=n gcm=y
RegexIterator:pub=y abs=n gcm=y
ParentIterator:pub=y abs=n gcm=y
RecursiveCallbackFilterIterator:pub=y abs=n gcm=y
RecursiveRegexIterator:pub=y abs=n gcm=y
