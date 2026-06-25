--TEST--
Stdlib: ReflectionProperty::getDocComment() (#11464)
--FILE--
<?php
class DocProp11464 {
    /** @var int */
    public int $documented = 1;
    public int $plain = 2;
}

$with = new ReflectionProperty(DocProp11464::class, 'documented');
$without = new ReflectionProperty(DocProp11464::class, 'plain');

echo "method_exists: ", (int) method_exists($with, 'getDocComment'), "\n";
$doc = $with->getDocComment();
echo "with_doc: ", (int) (is_string($doc) && str_contains($doc, '@var')), "\n";
echo "without_doc: ", var_export($without->getDocComment(), true), "\n";
--EXPECT--
method_exists: 1
with_doc: 1
without_doc: false
