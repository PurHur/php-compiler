<?php
declare(strict_types=1);
class C {
    protected int $x = 1;
    private int $y = 2;
    public int $p = 9;
}
$c = new C();
$readProtected = (function (): int { return $this->x; })->bindTo($c, null);
$readPrivate = (function (): int { return $this->y; })->bindTo($c, null);
$readPublic = (function (): int { return $this->p; })->bindTo($c, null);
try { echo $readProtected(), "\n"; } catch (Throwable $e) { echo 'protected: ', get_class($e), "\n"; }
try { echo $readPrivate(), "\n"; } catch (Throwable $e) { echo 'private: ', get_class($e), "\n"; }
echo $readPublic(), "\n";
