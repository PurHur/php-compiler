--TEST--
UConverter fromUCallback/toUCallback registration + convert dispatch (#20917)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter withheld until extension_loaded(\'intl\') (#19670/#6171)';
}
?>
--FILE--
<?php
echo 'from=', method_exists('UConverter', 'fromUCallback') ? 'yes' : 'no', "\n";
echo 'to=', method_exists('UConverter', 'toUCallback') ? 'yes' : 'no', "\n";

$base = new UConverter('US-ASCII', 'UTF-8');
echo 'base=', bin2hex($base->convert('π')), ' err=', $base->getErrorCode(), "\n";

class UConverterSubst20917 extends UConverter
{
    public int $hits = 0;

    public function fromUCallback(int $reason, array $source, int $codePoint, &$error): string|int|array|null
    {
        $this->hits++;
        $error = 0;

        return '?';
    }
}
$sub = new UConverterSubst20917('US-ASCII', 'UTF-8');
echo 'subst=', $sub->convert('π'), ' hits=', $sub->hits, "\n";

class UConverterSkip20917 extends UConverter
{
    public function fromUCallback(int $reason, array $source, int $codePoint, &$error): string|int|array|null
    {
        $error = 0;

        return null;
    }
}
$skip = new UConverterSkip20917('US-ASCII', 'UTF-8');
echo 'skip=', $skip->convert('Hello π World'), "\n";
?>
--EXPECT--
from=yes
to=yes
base=1a err=0
subst=? hits=1
skip=Hello  World
