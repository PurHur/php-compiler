<?php

declare(strict_types=1);

ob_start();
phpcredits(CREDITS_QA);
$qa = ob_get_clean();
if ('' === $qa) {
    fwrite(STDERR, "FAIL: CREDITS_QA output empty\n");
    exit(1);
}
if (!str_contains($qa, 'Quality Assurance')) {
    fwrite(STDERR, "FAIL: CREDITS_QA missing Quality Assurance heading\n");
    exit(1);
}

ob_start();
phpcredits(CREDITS_MODULES);
$modules = ob_get_clean();
if ('' === $modules) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES output empty\n");
    exit(1);
}

echo "ok\n";
