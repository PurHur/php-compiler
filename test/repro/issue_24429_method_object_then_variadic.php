<?php
// #24429 — instance method (Object $x, ...$rest) must not swap LLVM Variable types
// ($x as HT / $rest as object) or module verify fails on writeHashtable/setObjectAt.
namespace SpineChunk24429;

class Ctx
{
}

class Target
{
    public function call(Ctx $context, string ...$args): void
    {
        throw new \RuntimeException($context::class.'|'.count($args));
    }
}

try {
    (new Target())->call(new Ctx(), 'a', 'b');
} catch (\RuntimeException $e) {
    echo $e->getMessage(), "\n";
}
