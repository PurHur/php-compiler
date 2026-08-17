<?php
error_reporting(E_ALL);

class C
{
    public $x = 7;

    public function f_include()
    {
        return include __DIR__ . '/return_this_x.php';
    }

    public function f_require()
    {
        return require __DIR__ . '/return_this_x.php';
    }

    public function f_include_once()
    {
        return include_once __DIR__ . '/return_this_x_once.php';
    }

    public function f_require_once()
    {
        return require_once __DIR__ . '/return_this_x_reqonce.php';
    }

    public static function s_include()
    {
        try {
            return include __DIR__ . '/return_this_x.php';
        } catch (Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }
}

function f_include()
{
    try {
        return include __DIR__ . '/return_this_x.php';
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
}

$c = new C();
echo 'include=', $c->f_include(), "\n";
echo 'require=', $c->f_require(), "\n";
echo 'include_once=', $c->f_include_once(), "\n";
echo 'require_once=', $c->f_require_once(), "\n";
echo 'static=', C::s_include(), "\n";
echo 'function=', f_include(), "\n";
echo 'file=';
try {
    include __DIR__ . '/return_this_x.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
