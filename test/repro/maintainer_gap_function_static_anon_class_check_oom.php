<?php

declare(strict_types=1);

/**
 * Issue #15901 — FunctionStaticAnonymousClassCompileCheck must not OOM on CFG cycles.
 *
 * VM path (`bin/vm.php` this file): compile check runs during entry parseAndCompile.
 * Zend path: also exercises Runtime::parseAndCompile when the class is loadable.
 */
function loop_probe(): void
{
    for ($i = 0; $i < 3; $i++) {
        // back-edge exercises bounded CFG walk in compile check (#15910)
    }
}

loop_probe();

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}
if (class_exists('PHPCompiler\\Runtime', false)) {
    $runtime = new PHPCompiler\Runtime();
    $block = $runtime->parseAndCompile('<?php echo 1;', 'trivial.php');
    if (null === $block) {
        fwrite(STDERR, "fail: parseAndCompile returned null\n");
        exit(1);
    }
}

echo "ok\n";
