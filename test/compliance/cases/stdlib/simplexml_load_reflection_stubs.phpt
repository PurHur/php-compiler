--TEST--
stdlib simplexml_load_string/file Reflection stubs match php-src (#25510)
--FILE--
<?php
declare(strict_types=1);

foreach (['simplexml_load_string', 'simplexml_load_file'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '?', ' opt=', $p->isOptional() ? 'Y' : 'N';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
--EXPECT--
simplexml_load_string ret=SimpleXMLElement|false
  data type=string opt=N
  class_name type=?string opt=Y def='SimpleXMLElement'
  options type=int opt=Y def=0
  namespace_or_prefix type=string opt=Y def=''
  is_prefix type=bool opt=Y def=false
simplexml_load_file ret=SimpleXMLElement|false
  filename type=string opt=N
  class_name type=?string opt=Y def='SimpleXMLElement'
  options type=int opt=Y def=0
  namespace_or_prefix type=string opt=Y def=''
  is_prefix type=bool opt=Y def=false
