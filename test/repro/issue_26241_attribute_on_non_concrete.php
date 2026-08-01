<?php
// #26241 — PROFILE=8.5: #[\Attribute] on abstract/interface/trait/enum is compile-fatal
// (Zend/zend_attributes.c validate_attribute). Run one case via argv[1].
// Usage: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_26241_attribute_on_non_concrete.php abstract

$case = $argv[1] ?? 'abstract';
$decls = [
    'abstract' => '#[\Attribute] abstract class A {}',
    'interface' => '#[\Attribute] interface A {}',
    'trait' => '#[\Attribute] trait A {}',
    'enum' => '#[\Attribute] enum A { case X; }',
    'delayed' => <<<'PHP'
#[\DelayedTargetValidation]
#[\Attribute]
abstract class DelayedA {}
echo "OK delayed\n";
try {
    (new ReflectionClass(DelayedA::class))->getAttributes(Attribute::class)[0]->newInstance();
    echo "newInstance=ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP,
    'concrete' => <<<'PHP'
#[\Attribute]
class Marker {}
echo "OK concrete\n";
PHP,
];

if (!isset($decls[$case])) {
    fwrite(STDERR, "unknown case: {$case}\n");
    exit(2);
}

eval($decls[$case]);
if (!\in_array($case, ['delayed', 'concrete'], true)) {
    echo "should not reach\n";
}
