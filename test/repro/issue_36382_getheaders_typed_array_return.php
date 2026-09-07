<?php

/**
 * #36382 — typed `: array` return of a user property (TYPE_VALUE boxed HT) must
 * own a ref for the caller. Without ZVAL_COPY, foreach/var_dump SEGV under AOT
 * (Nyholm MessageTrait::getHeaders / Slim CGI header emit).
 *
 * php-src: Zend/zend_execute.c ZEND_RETURN ZVAL_COPY of IS_ARRAY.
 */
class Resp36382TypedArrayProp
{
    /** @var array<string, list<string>> */
    private $headers = ['Content-Type' => ['text/plain']];

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return 'hello';
    }
}

$r = new Resp36382TypedArrayProp();
foreach ($r->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        echo $name, ':', $value, "\n";
    }
}
echo $r->getBody(), "\n";
