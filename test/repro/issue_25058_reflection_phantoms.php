<?php
/**
 * Issue #25058 — ReflectionMethod 8.4 deprecated APIs + ReflectionFiber::getExecutingFiber phantoms.
 * php-src: ext/reflection/php_reflection.stub.php
 *
 * Run: php bin/vm.php test/repro/issue_25058_reflection_phantoms.php
 *      PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_25058_reflection_phantoms.php
 */
class C {
    #[\Deprecated(message: 'Old entry', since: '8.4')]
    function m() {}
}
$m = new ReflectionMethod('C', 'm');
echo 'depMsg=', method_exists($m, 'getDeprecatedMessage') ? 'yes' : 'no', "\n";
echo 'depVer=', method_exists($m, 'getDeprecatedVersion') ? 'yes' : 'no', "\n";
echo 'execFiber=', method_exists('ReflectionFiber', 'getExecutingFiber') ? 'yes' : 'no', "\n";
if (method_exists($m, 'getDeprecatedMessage')) {
    echo 'msg=', $m->getDeprecatedMessage(), "\n";
    echo 'ver=', $m->getDeprecatedVersion(), "\n";
}
