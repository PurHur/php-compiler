<?php
// Repro #30888 — ReflectionClass / Function / Parameter excess argc → ArgumentCountError
$r = new ReflectionClass(DateTime::class);
$o = new DateTime('now');
foreach ([
    fn () => $r->getName(1),
    fn () => $r->getShortName(1),
    fn () => $r->getFileName(1),
    fn () => $r->getNamespaceName(1),
    fn () => $r->inNamespace(1),
    fn () => $r->isInstantiable(1),
    fn () => $r->getParentClass(1),
    fn () => $r->hasMethod('format', 1),
    fn () => $r->getMethod('format', 1)->getName(),
    fn () => $r->getConstant('ATOM', 1),
    fn () => $r->isInstance($o, 1),
    fn () => (new ReflectionFunction('strlen'))->getName(1),
    fn () => (new ReflectionFunction('strlen'))->getParameters()[0]->getName(1),
] as $fn) {
    try {
        var_export($fn());
        echo " ACCEPTED\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok=', $r->getName(), ',', $r->getShortName(), ',', $r->hasMethod('format') ? '1' : '0', ',',
    $r->getMethod('format')->getName(), ',', $r->getConstant('ATOM'), ',',
    $r->isInstance($o) ? '1' : '0', ',',
    (new ReflectionFunction('strlen'))->getName(), ',',
    (new ReflectionFunction('strlen'))->getParameters()[0]->getName(), "\n";
