<?php
/**
 * #36382 — untyped export of scalar FastRoute rows (methodsCsv/pattern/id).
 */
class RC
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    protected array $fastRouteRows = [];

    public function map(array $methods, string $pattern, string $id): void
    {
        $csv = '';
        $first = true;
        foreach ($methods as $method) {
            if (!$first) {
                $csv .= ',';
            }
            $first = false;
            $csv .= $method;
        }
        $this->fastRouteRows[] = [$csv, $pattern, $id];
    }

    public function exportFastRouteRows()
    {
        return $this->fastRouteRows;
    }
}

$rc = new RC();
$rc->map(['GET'], '/hello', 'route0');
echo "B\n";
$rows = $rc->exportFastRouteRows();
echo "A\n";
echo 'M='.$rows[0][0].' P='.$rows[0][1].' ID='.$rows[0][2]."\n";
echo "OK\n";
