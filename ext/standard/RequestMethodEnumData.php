<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * RequestMethod string-backed enum cases (ext/standard/basic_functions.stub.php, issue #7230).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php enum RequestMethod: string
 *
 * @return array<string, string> case name => HTTP method
 */
final class RequestMethodEnumData
{
    /** @return array<string, string> */
    public static function cases(): array
    {
        return [
            'Get' => 'GET',
            'Post' => 'POST',
            'Put' => 'PUT',
            'Patch' => 'PATCH',
            'Delete' => 'DELETE',
            'Head' => 'HEAD',
            'Options' => 'OPTIONS',
        ];
    }
}
