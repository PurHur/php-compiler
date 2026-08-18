--TEST--
Language: extract(['this'=>1]) throws Cannot re-assign $this (#32226, ext/standard/array.c)
--FILE--
<?php
class CExtractThis {
    public function overwrite(): void {
        try {
            extract(['this' => 1]);
            echo "accepted\n";
        } catch (Error $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
            echo is_object($this) ? "this-ok\n" : "this-lost\n";
        }
    }

    public function skip(): void {
        extract(['this' => 1], EXTR_SKIP);
        echo is_object($this) ? "skip-ok\n" : "skip-lost\n";
    }

    public function prefixAll(): void {
        extract(['this' => 1], EXTR_PREFIX_ALL, 'p');
        echo isset($p_this) ? $p_this : 'undef';
        echo "\n";
        echo is_object($this) ? "prefix-ok\n" : "prefix-lost\n";
    }
}

(new CExtractThis())->overwrite();
(new CExtractThis())->skip();
(new CExtractThis())->prefixAll();
--EXPECT--
Error: Cannot re-assign $this
this-ok
skip-ok
1
prefix-ok
