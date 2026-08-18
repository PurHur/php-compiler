<?php
/**
 * Maintainer gap #32226: extract(['this'=>1]) in a method reassigns $this.
 * Zend: Error "Cannot re-assign $this" (rc=255).
 * php-src: ext/standard/array.c php_extract().
 */
class C {
    public function m(): void {
        extract(['this' => 1]);
        echo "accepted\n";
    }
}
(new C())->m();
