<?php
error_reporting(E_ALL);

class C
{
    public function f_include()
    {
        return include __DIR__ . '/return_self_class.php';
    }

    public function f_require()
    {
        return require __DIR__ . '/return_self_class.php';
    }

    public function f_include_once()
    {
        return include_once __DIR__ . '/return_self_class_once.php';
    }

    public function f_require_once()
    {
        return require_once __DIR__ . '/return_self_class_reqonce.php';
    }
}

class D extends C
{
    public function f_static()
    {
        return include __DIR__ . '/return_static_class.php';
    }
}

$c = new C();
echo 'include=self=', $c->f_include(), "\n";
echo 'require=self=', $c->f_require(), "\n";
echo 'include_once=self=', $c->f_include_once(), "\n";
echo 'require_once=self=', $c->f_require_once(), "\n";

$d = new D();
echo 'static_lsb=static=', $d->f_static(), "\n";

echo 'file=';
try {
    include __DIR__ . '/return_self_class.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
