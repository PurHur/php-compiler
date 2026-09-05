<?php
// Minimal stand-in for Nyholm StreamTrait version-gated trait (#36382 empty CGI).
// Before patch: top-level if (PHP_VERSION_ID >= 70400) { trait T {} } else { trait T {} }
// under incremental IncludeHelper AOT ret-void'd {main} before echo.
interface StreamInterface36382
{
    public function __toString(): string;
}

if (\PHP_VERSION_ID >= 70400) {
    trait StreamTrait36382
    {
        public function __toString(): string
        {
            return 'trait-ok';
        }
    }
} else {
    trait StreamTrait36382
    {
        public function __toString()
        {
            return 'trait-old';
        }
    }
}

class Stream36382
{
    use StreamTrait36382;
}

echo (new Stream36382())->__toString(), "\n";
echo "AFTER_TRAIT\n";
