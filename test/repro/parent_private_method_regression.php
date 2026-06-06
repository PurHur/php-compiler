<?php

declare(strict_types=1);

class A
{
    private function secret(): string
    {
        return 'ok';
    }
}

class B extends A
{
    public function go(): void
    {
        echo parent::secret();
    }
}

try {
    (new B())->go();
    echo 'no-error';
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
