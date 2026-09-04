<?php
declare(strict_types=1);

/**
 * #36382 — clone $this must specialize to the method class, not every registered class.
 * Without restriction, Slim-sized graphs stall for minutes on Uri::withUserInfo TYPE_CLONE.
 */
class CloneProbe36382
{
    private string $v;

    public function __construct(string $v)
    {
        $this->v = $v;
    }

    public function withV(string $v): self
    {
        if ($this->v === $v) {
            return $this;
        }
        $new = clone $this;
        $new->v = $v;

        return $new;
    }

    public function getV(): string
    {
        return $this->v;
    }
}

class ClonePad36382_0 { public int $n = 0; }
class ClonePad36382_1 { public int $n = 1; }
class ClonePad36382_2 { public int $n = 2; }
class ClonePad36382_3 { public int $n = 3; }
class ClonePad36382_4 { public int $n = 4; }
class ClonePad36382_5 { public int $n = 5; }
class ClonePad36382_6 { public int $n = 6; }
class ClonePad36382_7 { public int $n = 7; }
class ClonePad36382_8 { public int $n = 8; }
class ClonePad36382_9 { public int $n = 9; }
class ClonePad36382_10 { public int $n = 10; }
class ClonePad36382_11 { public int $n = 11; }
class ClonePad36382_12 { public int $n = 12; }
class ClonePad36382_13 { public int $n = 13; }
class ClonePad36382_14 { public int $n = 14; }
class ClonePad36382_15 { public int $n = 15; }
class ClonePad36382_16 { public int $n = 16; }
class ClonePad36382_17 { public int $n = 17; }
class ClonePad36382_18 { public int $n = 18; }
class ClonePad36382_19 { public int $n = 19; }
class ClonePad36382_20 { public int $n = 20; }
class ClonePad36382_21 { public int $n = 21; }
class ClonePad36382_22 { public int $n = 22; }
class ClonePad36382_23 { public int $n = 23; }
class ClonePad36382_24 { public int $n = 24; }
class ClonePad36382_25 { public int $n = 25; }
class ClonePad36382_26 { public int $n = 26; }
class ClonePad36382_27 { public int $n = 27; }
class ClonePad36382_28 { public int $n = 28; }
class ClonePad36382_29 { public int $n = 29; }
class ClonePad36382_30 { public int $n = 30; }
class ClonePad36382_31 { public int $n = 31; }
class ClonePad36382_32 { public int $n = 32; }
class ClonePad36382_33 { public int $n = 33; }
class ClonePad36382_34 { public int $n = 34; }
class ClonePad36382_35 { public int $n = 35; }
class ClonePad36382_36 { public int $n = 36; }
class ClonePad36382_37 { public int $n = 37; }
class ClonePad36382_38 { public int $n = 38; }
class ClonePad36382_39 { public int $n = 39; }
class ClonePad36382_40 { public int $n = 40; }
class ClonePad36382_41 { public int $n = 41; }
class ClonePad36382_42 { public int $n = 42; }
class ClonePad36382_43 { public int $n = 43; }
class ClonePad36382_44 { public int $n = 44; }
class ClonePad36382_45 { public int $n = 45; }
class ClonePad36382_46 { public int $n = 46; }
class ClonePad36382_47 { public int $n = 47; }
class ClonePad36382_48 { public int $n = 48; }
class ClonePad36382_49 { public int $n = 49; }
class ClonePad36382_50 { public int $n = 50; }
class ClonePad36382_51 { public int $n = 51; }
class ClonePad36382_52 { public int $n = 52; }
class ClonePad36382_53 { public int $n = 53; }
class ClonePad36382_54 { public int $n = 54; }
class ClonePad36382_55 { public int $n = 55; }
class ClonePad36382_56 { public int $n = 56; }
class ClonePad36382_57 { public int $n = 57; }
class ClonePad36382_58 { public int $n = 58; }
class ClonePad36382_59 { public int $n = 59; }
class ClonePad36382_60 { public int $n = 60; }
class ClonePad36382_61 { public int $n = 61; }
class ClonePad36382_62 { public int $n = 62; }
class ClonePad36382_63 { public int $n = 63; }
class ClonePad36382_64 { public int $n = 64; }
class ClonePad36382_65 { public int $n = 65; }
class ClonePad36382_66 { public int $n = 66; }
class ClonePad36382_67 { public int $n = 67; }
class ClonePad36382_68 { public int $n = 68; }
class ClonePad36382_69 { public int $n = 69; }
class ClonePad36382_70 { public int $n = 70; }
class ClonePad36382_71 { public int $n = 71; }
class ClonePad36382_72 { public int $n = 72; }
class ClonePad36382_73 { public int $n = 73; }
class ClonePad36382_74 { public int $n = 74; }
class ClonePad36382_75 { public int $n = 75; }
class ClonePad36382_76 { public int $n = 76; }
class ClonePad36382_77 { public int $n = 77; }
class ClonePad36382_78 { public int $n = 78; }
class ClonePad36382_79 { public int $n = 79; }

// Touch pad classes so they register in the AOT module.
$pads = [];
for ($i = 0; $i < 80; ++$i) {
    $c = 'ClonePad36382_'.$i;
    $pads[] = new $c();
}

$a = new CloneProbe36382('a');
$b = $a->withV('b');
echo $a->getV(), '|', $b->getV(), "\n";
