<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

final class MimeContentTypeJitHelper
{
    public static function mimeContentType(string $path): ?string
    {
        $data = @\file_get_contents($path);
        if (false === $data) {
            return "FAIL";
        }
        $sub = \substr($data, 0, 5);
        $eq = ('<?php' === $sub) ? 'Y' : 'N';
        $eq2 = ('127.0' === $sub) ? 'Y' : 'N';

        return "sub=$sub eqPhp=$eq eq127=$eq2";
    }
}
