<?php
/**
 * ob_list_handlers / ob_get_contents / ob_get_length excess argc → ArgumentCountError (#30683).
 * php-src: ext/standard/output.c
 */
foreach (['ob_list_handlers', 'ob_get_contents', 'ob_get_length'] as $f) {
    try {
        $f(1);
        echo "$f NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo "$f ", get_class($e), ': ', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo "$f ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}

ob_start();
echo 'x';
$c = ob_get_contents();
$l = ob_get_length();
$h = ob_list_handlers();
ob_end_clean();
echo 'ok contents=', var_export($c, true), ' length=', var_export($l, true), ' handlers=', is_array($h) ? 'array' : gettype($h), "\n";
