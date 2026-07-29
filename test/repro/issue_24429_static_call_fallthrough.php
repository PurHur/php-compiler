<?php
/**
 * Dynamic static-call fallthrough under PHP_COMPILER_SPINE_CHUNK=1 (#24429).
 *
 * sockets/vm chunks abort with "Static call class must be a literal" when the
 * class operand is not a CFG Literal (`$class::method()`, or a slot whose
 * Literal fell out of Block::scope). With SPINE_CHUNK=1 that must fall through
 * to ExternalMethod like instance methods (#24496), not abort compile.
 *
 *   # aborts without flag
 *   php bin/compile.php -o /tmp/x test/repro/issue_24429_static_call_fallthrough.php
 *
 *   # with flag: compiles + reports object::paint
 *   PHP_COMPILER_SPINE_CHUNK=1 PHP_COMPILER_REPORT_EXTERNAL_STUBS=1 \
 *     php bin/compile.php -o /tmp/x test/repro/issue_24429_static_call_fallthrough.php
 */
function callDynamic(string $class): int
{
    return $class::paint();
}

echo callDynamic(\OtherChunk\Widget::class);
