<?php

declare(strict_types=1);

/** @deprecated */
class DocblockDeprecatedClass
{
}

class AttributeDeprecatedClass
{
}

echo method_exists(ReflectionClass::class, 'isDeprecated') ? 'class_method=yes' : 'class_method=no', "\n";

$rDoc = new ReflectionClass(DocblockDeprecatedClass::class);
echo method_exists($rDoc, 'isDeprecated') ? 'instance_docblock=yes' : 'instance_docblock=no', "\n";

$rAttr = new ReflectionClass(AttributeDeprecatedClass::class);
echo method_exists($rAttr, 'isDeprecated') ? 'instance_attr=yes' : 'instance_attr=no', "\n";
