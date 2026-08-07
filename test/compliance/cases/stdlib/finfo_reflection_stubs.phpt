--TEST--
stdlib finfo_open/file/buffer/close Reflection stubs match php-src (#25471, #28569)
--FILE--
<?php
declare(strict_types=1);

foreach (['finfo_open', 'finfo_file', 'finfo_buffer', 'finfo_close'] as $fn) {
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
finfo_open ret=finfo|false
  flags type=int opt=Y def=0
  magic_database type=?string opt=Y def=NULL
finfo_file ret=string|false
  finfo type=finfo opt=N
  filename type=string opt=N
  flags type=int opt=Y def=0
  context type=? opt=Y def=NULL
finfo_buffer ret=string|false
  finfo type=finfo opt=N
  string type=string opt=N
  flags type=int opt=Y def=0
  context type=? opt=Y def=NULL
finfo_close ret=bool
  finfo type=finfo opt=N
