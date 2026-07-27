--TEST--
Language: attributes on file-scope constants under PROFILE=8.5 (#23882, Zend/zend_language_parser.y)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
#[Attribute]
class Marker {}

#[Marker]
const MARKED = 42;

echo 'val=', MARKED, "\n";
$r = new ReflectionConstant('MARKED');
$attrs = $r->getAttributes();
echo 'nattrs=', count($attrs), "\n";
echo 'attr0=', $attrs[0]->getName(), "\n";
--EXPECT--
val=42
nattrs=1
attr0=Marker
