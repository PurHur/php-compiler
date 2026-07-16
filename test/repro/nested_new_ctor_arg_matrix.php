<?php

declare(strict_types=1);

class Inner
{
    public $a;
    public $b;

    public function __construct($a, $b = null)
    {
        $this->a = $a;
        $this->b = $b;
    }
}

class Outer
{
    public $x;
    public $y;

    public function __construct($x, $y = null)
    {
        $this->x = $x;
        $this->y = $y;
    }
}

function show(string $label, callable $fn): void
{
    echo $label, ': ';
    try {
        $fn();
        echo "ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

show('outer(inner(1), 2)', static function (): void {
    $o = new Outer(new Inner(1), 2);
    echo "a={$o->x->a} y={$o->y} ";
});

show('outer(inner(1,2), 3)', static function (): void {
    $o = new Outer(new Inner(1, 2), 3);
    echo "a={$o->x->a} b={$o->x->b} y={$o->y} ";
});

show('outer(inner(1, SKIP_DOTS), 3)', static function (): void {
    $o = new Outer(new Inner(1, FilesystemIterator::SKIP_DOTS), 3);
    echo "a={$o->x->a} b={$o->x->b} y={$o->y} ";
});

show('RII nested literal mode', static function (): void {
    $tmp = sys_get_temp_dir() . '/nm_' . uniqid('', true);
    mkdir($tmp);
    file_put_contents($tmp . '/a.txt', 'x');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        1
    );
    echo get_class($it), ' ';
    @unlink($tmp . '/a.txt');
    @rmdir($tmp);
});

show('RII nested const mode', static function (): void {
    $tmp = sys_get_temp_dir() . '/nm2_' . uniqid('', true);
    mkdir($tmp);
    file_put_contents($tmp . '/a.txt', 'x');
    mkdir($tmp . '/sub');
    file_put_contents($tmp . '/sub/b.txt', 'y');
    $names = [];
    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ) as $f
    ) {
        $names[] = $f->getFilename();
    }
    sort($names);
    echo json_encode($names), ' ';
    @unlink($tmp . '/a.txt');
    @unlink($tmp . '/sub/b.txt');
    @rmdir($tmp . '/sub');
    @rmdir($tmp);
});

show('RII nested no mode', static function (): void {
    $tmp = sys_get_temp_dir() . '/nm3_' . uniqid('', true);
    mkdir($tmp);
    file_put_contents($tmp . '/a.txt', 'x');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS)
    );
    echo get_class($it), ' ';
    @unlink($tmp . '/a.txt');
    @rmdir($tmp);
});
