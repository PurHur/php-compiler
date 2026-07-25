<?php
// Issue #23068 — public private(set) is implicitly final (zend_API.c / ReflectionProperty::isFinal).
class C
{
    public private(set) string $job = 'x';
    public string $name = 'n';
}
$job = new ReflectionProperty(C::class, 'job');
$name = new ReflectionProperty(C::class, 'name');
if (!$job->isFinal()) {
    fwrite(STDERR, "fail: private(set) isFinal false\n");
    exit(1);
}
if ($name->isFinal()) {
    fwrite(STDERR, "fail: plain public isFinal true\n");
    exit(1);
}
if (($job->getModifiers() & ReflectionProperty::IS_FINAL) === 0) {
    fwrite(STDERR, 'fail: getModifiers missing IS_FINAL bit mods=' . $job->getModifiers() . "\n");
    exit(1);
}
echo "ok\n";
