--TEST--
Language: nested-in-function attribute class ReflectionAttribute::newInstance (#22029, Zend/zend_attributes.c)
--FILE--
<?php
function probe() {
    #[Attribute]
    class NestedAttr {
        public function __construct(public int $x = 0) {}
    }
    #[NestedAttr(5)]
    class NestedTarget {}
    $a = (new ReflectionClass(NestedTarget::class))->getAttributes()[0] ?? null;
    if (!$a) {
        echo "null\n";

        return;
    }
    echo $a->getName(), "\n";
    echo json_encode($a->getArguments()), "\n";
    echo $a->newInstance()->x, "\n";
}
probe();

#[Attribute]
class FileAttr {
    public function __construct(public int $x = 0) {}
}
#[FileAttr(3)]
class FileTarget {}
function probeFileScope() {
    $a = (new ReflectionClass(FileTarget::class))->getAttributes()[0];
    echo $a->getName(), "\n";
    echo json_encode($a->getArguments()), "\n";
    echo $a->newInstance()->x, "\n";
}
probeFileScope();
--EXPECT--
NestedAttr
[5]
5
FileAttr
[3]
3
