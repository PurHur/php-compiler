<?php
/**
 * #36380 — preg_match($pat, $ex['text'], $matches) with uninitialized $matches must
 * write the match array to $matches, not clobber $ex['text'] (ZEND_SEND_REF).
 *
 * php-src: Zend/zend_execute.c ZEND_SEND_REF; ext/pcre/php_pcre.c php_pcre_match_impl.
 */
class P {
    public function run(array $Ex): void
    {
        preg_match('/x/', $Ex['text'], $m);
        echo 'm=', json_encode($m), ' text=', json_encode($Ex['text']), "\n";
    }
}
(new P())->run(['text' => 'x']);
