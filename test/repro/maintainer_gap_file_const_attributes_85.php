<?php
/**
 * PROFILE=8.5: attributes on file-scope constants (php-src 8.5).
 * #[\Deprecated] already works; general #[Attr] const must parse + reflect.
 */
error_reporting(E_ALL);

#[Attribute]
class Marker {}

#[Marker]
const MARKED = 42;

echo 'val=', MARKED, "\n";
$r = new ReflectionConstant('MARKED');
$attrs = $r->getAttributes();
echo 'nattrs=', count($attrs), "\n";
echo 'attr0=', $attrs[0]->getName(), "\n";
