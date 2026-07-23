<?php
$ao = new ArrayObject(["p" => "q"], ArrayObject::ARRAY_AS_PROPS);
echo "isset_p=", isset($ao->p) ? "1" : "0", "\n";
echo "read_p=", var_export($ao->p, true), "\n";
$ao->foo = "bar";
echo "isset_foo=", isset($ao->foo) ? "1" : "0", "\n";
echo "read_foo=", var_export($ao->foo, true), "\n";
echo "idx_foo=", var_export($ao["foo"], true), "\n";
$ai = new ArrayIterator(["p" => "q"], ArrayIterator::ARRAY_AS_PROPS);
echo "ai_flags=", $ai->getFlags(), "\n";
echo "ai_isset=", isset($ai->p) ? "1" : "0", "\n";
echo "ai_read=", var_export($ai->p, true), "\n";
$ao2 = new ArrayObject(["a" => 1], ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS);
$ao2->b = 2;
echo "ao2_isset_b=", isset($ao2->b) ? "1" : "0", "\n";
echo "ao2_b=", var_export($ao2->b, true), "\n";
