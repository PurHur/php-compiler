--TEST--
Stdlib: Soap\Url / Soap\Sdl final internal classes on soap PROFILE=8.4+ (#23230, ext/soap/soap.stub.php)
--FILE--
<?php
declare(strict_types=1);

echo 'url=', (int) class_exists('Soap\\Url', false), "\n";
echo 'sdl=', (int) class_exists('Soap\\Sdl', false), "\n";
$ru = new ReflectionClass('Soap\\Url');
$rs = new ReflectionClass('Soap\\Sdl');
echo 'url_final=', (int) $ru->isFinal(), ' url_internal=', (int) $ru->isInternal(), "\n";
echo 'sdl_final=', (int) $rs->isFinal(), ' sdl_internal=', (int) $rs->isInternal(), "\n";
try {
    new Soap\Url();
    echo "url_ctor=ok\n";
} catch (Error $e) {
    echo 'url_ctor=', $e->getMessage(), "\n";
}
try {
    new Soap\Sdl();
    echo "sdl_ctor=ok\n";
} catch (Error $e) {
    echo 'sdl_ctor=', $e->getMessage(), "\n";
}
?>
--EXPECT--
url=1
sdl=1
url_final=1 url_internal=1
sdl_final=1 sdl_internal=1
url_ctor=Cannot directly construct Soap\Url
sdl_ctor=Cannot directly construct Soap\Sdl
