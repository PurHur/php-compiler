<?php
/**
 * #28628 — array-literal enum keys must use Zend 8.3+ typed TypeError text
 * (same as `$a[$enum] = …`), not legacy "Illegal offset type".
 *
 * php-src: Zend/zend_hash.c / Zend/zend.c zend_illegal_container_offset()
 */
enum E { case A; }
enum S: string { case A = 'a'; }

foreach ([['unit', E::A], ['backed', S::A]] as [$label, $k]) {
    try {
        $arr = [$k => 1];
        echo "$label: accepted\n";
    } catch (Throwable $e) {
        echo "$label: ", get_class($e), ':', $e->getMessage(), "\n";
    }
    try {
        $a = [];
        $a[$k] = 1;
        echo "$label-assign: accepted\n";
    } catch (Throwable $e) {
        echo "$label-assign: ", get_class($e), ':', $e->getMessage(), "\n";
    }
}
