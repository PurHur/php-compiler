--TEST--
Language: Attribute is final — isFinal() + valid #[Attribute] (#21669, Zend/zend_attributes.c)
--FILE--
<?php
echo (new ReflectionClass(Attribute::class))->isFinal() ? "attribute_final_yes\n" : "attribute_final_no\n";

#[Attribute]
class MyAttr {}
$a = new MyAttr();
echo "myattr_ok\n";
echo (new ReflectionClass(MyAttr::class))->getAttributes()[0]->getName(), "\n";
--EXPECT--
attribute_final_yes
myattr_ok
Attribute
