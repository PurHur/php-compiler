<?php declare(strict_types=1);
class MiniService { public function id(): int { return 42; } }
function invoke(?MiniService $svc): void { $svc?->id(); }
invoke(null); invoke(new MiniService()); echo "done\n";
