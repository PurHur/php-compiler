--TEST--
IntlListFormatter on PROFILE=8.5 + host intl (#23229)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    die('skip IntlListFormatter withheld until extension_loaded(\'intl\')');
}
if (!\PHPCompiler\CompilerVersion::advertisesIntlListFormatter()) {
    die('skip IntlListFormatter requires PHP_COMPILER_PROFILE=8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

echo 'class=', class_exists('IntlListFormatter') ? '1' : '0', "\n";
echo 'final=', (new ReflectionClass('IntlListFormatter'))->isFinal() ? '1' : '0', "\n";
echo 'TYPE_AND=', (int) IntlListFormatter::TYPE_AND, "\n";
echo 'WIDTH_WIDE=', (int) IntlListFormatter::WIDTH_WIDE, "\n";

$f = new IntlListFormatter('en_US');
echo 'en=', $f->format(['A', 'B', 'C']), "\n";
echo 'empty=', var_export($f->format([]), true), "\n";
echo 'nums=', $f->format([1, 2, 3]), "\n";

$fOr = new IntlListFormatter('en_US', IntlListFormatter::TYPE_OR, IntlListFormatter::WIDTH_WIDE);
echo 'or=', $fOr->format(['A', 'B', 'C']), "\n";

$fr = new IntlListFormatter('FR', IntlListFormatter::TYPE_AND, IntlListFormatter::WIDTH_WIDE);
echo 'fr=', $fr->format([1, 2, 3]), "\n";

echo 'err=', $f->getErrorCode(), "\n";
echo 'errmsg=', $f->getErrorMessage(), "\n";
?>
--EXPECT--
class=1
final=1
TYPE_AND=0
WIDTH_WIDE=0
en=A, B, and C
empty=''
nums=1, 2, and 3
or=A, B, or C
fr=1, 2 et 3
err=0
errmsg=U_ZERO_ERROR
