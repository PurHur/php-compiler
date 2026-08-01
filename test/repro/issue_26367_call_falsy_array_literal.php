<?php

declare(strict_types=1);

/**
 * Call + array literal with false/true/null/[] must keep sibling call result (#26367).
 *
 * @see https://github.com/PurHur/php-compiler/issues/26367
 * php-src: Zend/zend_execute.c / zend_compile.c — arg temps vs array init
 */

function show($a, $b): void
{
    echo 'a=', gettype($a), ' b=', gettype($b), "\n";
    echo 'a_val=', json_encode($a), ' b_val=', json_encode($b), "\n";
}

show(strtoupper('x'), ['k' => false]);
echo "---\n";
show(strtoupper('x'), ['k' => true]);
echo "---\n";
show(strtoupper('x'), ['k' => null]);
echo "---\n";
show(strtoupper('x'), ['k' => []]);
echo "---\n";
show(strtoupper('x'), ['k' => 0]);
echo "---\n";

class S
{
    public $v = 1;
}

try {
    $u = unserialize(serialize(new S()), ['allowed_classes' => false]);
    echo 'unserialize=', get_class($u), "\n";
} catch (Throwable $e) {
    echo 'unserialize=', get_class($e), ':', strtok($e->getMessage(), "\n"), "\n";
}
