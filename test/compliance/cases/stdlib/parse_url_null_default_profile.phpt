--TEST--
stdlib parse_url(null) coerce on default profile (#20110, ext/standard/url.c)
--FILE--
<?php
echo var_export(parse_url(null), true), "\n";
?>
--EXPECT--
array (
  'path' => '',
)
