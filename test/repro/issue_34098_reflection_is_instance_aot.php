<?php
// Repro #34098 — ReflectionClass::isInstance thin AOT
class BaseIsi
{
}

class ChildIsi extends BaseIsi
{
}

interface IsiIface
{
}

class IsiImpl implements IsiIface
{
}

$ra = new ReflectionClass(BaseIsi::class);
$ri = new ReflectionClass(IsiIface::class);

echo ($ra->isInstance(new BaseIsi()) ? '1' : '0'), ',';
echo ($ra->isInstance(new ChildIsi()) ? '1' : '0'), ',';
echo ($ra->isInstance(new stdClass()) ? '1' : '0'), ',';
echo ($ri->isInstance(new IsiImpl()) ? '1' : '0'), ',';
echo ($ri->isInstance(new BaseIsi()) ? '1' : '0'), "\n";
