<?php

declare(strict_types=1);

/**
 * #36382 — docblock `@var string` props (Nyholm MessageTrait::$protocol) load as
 * __string__*; Native object-param compileArg must not __value__readObject them.
 * php-src: Zend/zend_API.c zend_parse_arg_object / Z_TYPE_P.
 */
class Msg
{
    /** @var string */
    private $protocol = '1.1';

    public function take(?object $o): void
    {
        echo null === $o ? 'null' : 'obj';
        echo "\n";
    }

    public function run(): void
    {
        $this->take($this->protocol);
    }
}

(new Msg())->run();
