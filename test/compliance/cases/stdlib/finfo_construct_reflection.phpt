--TEST--
stdlib finfo::__construct Reflection names flags/magic_database (#26181)
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionMethod('finfo', '__construct');
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE',
        ' opt=', $p->isOptional() ? '1' : '0';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
$f = new finfo(flags: FILEINFO_MIME_TYPE);
echo 'flags_named=', $f->buffer('<?php'), "\n";
try {
    new finfo(options: FILEINFO_MIME_TYPE);
    echo "options_named=UNEXPECTED\n";
} catch (Error $e) {
    echo 'options:', $e->getMessage(), "\n";
}
--EXPECT--
$flags:int opt=1 def=0
$magic_database:?string opt=1 def=NULL
flags_named=text/x-php
options:Unknown named parameter $options
