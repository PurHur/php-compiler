<?php
class Base
{
    private function hidden(): void
    {
    }
}

class T extends Base
{
    public function m(string $x, int $y = 0): string
    {
        return $x;
    }

    public static function s(int $n = 2): int
    {
        return $n;
    }

    protected function p(): void
    {
    }
}

$m = new ReflectionMethod(T::class, 'm');
$s = new ReflectionMethod(T::class, 's');
$p = new ReflectionMethod(T::class, 'p');
echo 'm_pub=', $m->isPublic() ? '1' : '0',
    ' m_static=', $m->isStatic() ? '1' : '0', "\n";
echo 's_pub=', $s->isPublic() ? '1' : '0',
    ' s_static=', $s->isStatic() ? '1' : '0', "\n";
echo 'p_pub=', $p->isPublic() ? '1' : '0',
    ' p_static=', $p->isStatic() ? '1' : '0', "\n";
