--TEST--
PCRE preg_replace/callback/filter/split Reflection limit=-1 count=NULL (#24969, ext/pcre/php_pcre.stub.php)
--FILE--
<?php
foreach (['preg_replace', 'preg_replace_callback', 'preg_filter', 'preg_split'] as $fn) {
    $rf = new ReflectionFunction($fn);
  echo $fn, "\n";
  foreach ($rf->getParameters() as $p) {
    $default = $p->isDefaultValueAvailable()
      ? var_export($p->getDefaultValue(), true)
      : ($p->isOptional() ? 'OPT' : 'REQ');
    echo '  ', $p->getName(), '=', $default, "\n";
  }
}
?>
--EXPECT--
preg_replace
  pattern=REQ
  replacement=REQ
  subject=REQ
  limit=-1
  count=NULL
preg_replace_callback
  pattern=REQ
  callback=REQ
  subject=REQ
  limit=-1
  count=NULL
  flags=0
preg_filter
  pattern=REQ
  replacement=REQ
  subject=REQ
  limit=-1
  count=NULL
preg_split
  pattern=REQ
  subject=REQ
  limit=-1
  flags=0
