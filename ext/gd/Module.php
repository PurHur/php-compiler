<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * gd extension module entry (php-src ext/gd/gd.c; issue #7407).
 *
 * Register under {@see standard}; advertise {@code gd} via
 * {@see getAdditionalExtensionNames()} only when host Zend has php-gd
 * ({@see GdExtensionPolicy}, #22740 / re-#11675). PHP-in-PHP decode/draw stays
 * in-tree for hosts with php-gd (#3496 / #6215).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!GdExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['gd'];
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        if (GdExtensionPolicy::advertisesDrawing()) {
            foreach (GdConstants::REGISTERED as $name => $value) {
                $var = new VM\Variable();
                $var->int($value);
                $runtime->vmContext->defineConstant($name, $var);
            }
        }
    }

    public function getFunctions(): array
    {
        if (!GdExtensionPolicy::advertisesExtension()) {
            return [];
        }

        $functions = [
            new imagecreate(),
            new imagecreatetruecolor(),
            new gd_info(),
            new imagetypes(),
        ];
        if (GdExtensionPolicy::advertisesDrawing()) {
            $functions[] = new imagecolorallocate();
            $functions[] = new imagecolorallocatealpha();
            $functions[] = new imagecolorsforindex();
            $functions[] = new imagecolorclosest();
            $functions[] = new imagecolorclosestalpha();
            $functions[] = new imagecolorclosesthwb();
            $functions[] = new imagecolorexact();
            $functions[] = new imagecolorexactalpha();
            $functions[] = new imagecolorresolve();
            $functions[] = new imagecolorresolvealpha();
            $functions[] = new imagecolorset();
            $functions[] = new imagecolortransparent();
            $functions[] = new imagecolormatch();
            $functions[] = new imagealphablending();
            $functions[] = new imagelayereffect();
            $functions[] = new imageresolution();
            $functions[] = new imagesavealpha();
            $functions[] = new imageantialias();
            $functions[] = new imagesetthickness();
            $functions[] = new imagesetbrush();
            $functions[] = new imagesetstyle();
            $functions[] = new imageistruecolor();
            $functions[] = new imagetruecolortopalette();
            $functions[] = new imagepalettetotruecolor();
            $functions[] = new imagesetinterpolation();
            $functions[] = new imagegetinterpolation();
            $functions[] = new imagefill();
            $functions[] = new imagefilltoborder();
            $functions[] = new imagedestroy();
            $functions[] = new imagesx();
            $functions[] = new imagesy();
            $functions[] = new imagecolorat();
            $functions[] = new imagecopy();
            $functions[] = new imagecopymerge();
            $functions[] = new imagecopyresampled();
            $functions[] = new imagecopyresized();
            $functions[] = new imagesetpixel();
            $functions[] = new imageline();
            $functions[] = new imagedashedline();
            $functions[] = new imagerectangle();
            $functions[] = new imageellipse();
            $functions[] = new imagefilledellipse();
            $functions[] = new imagearc();
            $functions[] = new imagefilledarc();
            $functions[] = new imagepolygon();
            $functions[] = new imageopenpolygon();
            $functions[] = new imagefilledpolygon();
            $functions[] = new imagefilledrectangle();
            $functions[] = new imagestring();
            $functions[] = new imagestringup();
            $functions[] = new imagechar();
            $functions[] = new imagecharup();
            $functions[] = new imageloadfont();
            $functions[] = new imagegammacorrect();
            $functions[] = new imageinterlace();
            $functions[] = new imagesetclip();
            $functions[] = new imagegetclip();
            if (VmGdFreeType::available()) {
                $functions[] = new imagefttext();
                $functions[] = new imageftbbox();
                $functions[] = new imagettftext();
                $functions[] = new imagettfbbox();
            }
            $functions[] = new imagefilter();
            $functions[] = new imageflip();
            $functions[] = new imagecrop();
            $functions[] = new imagecropauto();
            $functions[] = new imagerotate();
            $functions[] = new imagescale();
            $functions[] = new imageaffine();
            $functions[] = new imageaffinematrixget();
            $functions[] = new imageaffinematrixconcat();
            $functions[] = new imageconvolution();
        }
        if (GdExtensionPolicy::advertisesDecodeFromString()) {
            $functions[] = new imagecreatefromstring();
            $functions[] = new imagepng();
            $functions[] = new imagejpeg();
            $functions[] = new imagegif();
            $functions[] = new imagewebp();
            $functions[] = new imageavif();
            $functions[] = new imagebmp();
            $functions[] = new imagewbmp();
            $functions[] = new imagexbm();
            $functions[] = new imagegd();
            $functions[] = new imagegd2();
            $functions[] = new imagecreatefrompng();
            $functions[] = new imagecreatefromjpeg();
            $functions[] = new imagecreatefromgif();
            $functions[] = new imagecreatefromwebp();
            $functions[] = new imagecreatefromavif();
            $functions[] = new imagecreatefrombmp();
            $functions[] = new imagecreatefromwbmp();
            $functions[] = new imagecreatefromxbm();
            $functions[] = new imagecreatefromxpm();
            $functions[] = new imagecreatefromgd();
            $functions[] = new imagecreatefromgd2();
            $functions[] = new imagecreatefromgd2part();
            $functions[] = new imagecreatefromtga();
        }

        return $functions;
    }
}
