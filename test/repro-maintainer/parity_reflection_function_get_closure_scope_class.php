<?php
// php-src ext/reflection/php_reflection.c — getClosureScopeClass() (#11250).

class C {
    public function m() {}
}

$c = Closure::fromCallable([new C(), 'm']);
$scope = (new ReflectionFunction($c))->getClosureScopeClass();
if (null === $scope || 'C' !== $scope->getName()) {
    fwrite(STDERR, "getClosureScopeClass() returned ".var_export($scope?->getName(), true)."\n");
    exit(1);
}
echo "ok\n";
