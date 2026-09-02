<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Variable;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * zip extension module entry (php-src ext/zip/php_zip.c; issues #5869, #3337, #6370, #18137).
 *
 * Register under {@see standard}; advertise logical {@code zip} extension, ZipArchive, and
 * zip_* procedural API when {@see ZipExtensionPolicy::advertisesExtension()} — withheld on
 * reference profile (Zend 8.2 harness has no ext/zip).
 */
class Module extends ModuleAbstract
{
    /**
     * ZipArchive thin-AOT Call proxies + ClassConstFetch / stub props (#36204 / #35002 / #20712).
     */
    public function jitInit(JIT\Context $context): void
    {
        $context->functionProxies['ziparchive::__construct'] = new JIT\Call\ZipArchiveConstruct();
        foreach ([
            'open',
            'addFromString',
            'addEmptyDir',
            'addFile',
            'close',
            'getFromName',
            'locateName',
            'getFromIndex',
            'getNameIndex',
            'renameName',
            'renameIndex',
            'deleteName',
            'deleteIndex',
            'extractTo',
            'getStatusString',
            'count',
            'isWritable',
            'setReadOnly',
            'setArchiveComment',
            'getArchiveComment',
            'setCommentName',
            'getCommentName',
            'setCommentIndex',
            'getCommentIndex',
            'unchangeAll',
            'unchangeArchive',
            'unchangeIndex',
            'unchangeName',
            'replaceFile',
            'isCompressionMethodSupported',
            'isEncryptionMethodSupported',
            'setPassword',
            'setCompressionName',
            'setCompressionIndex',
            'setEncryptionName',
            'setEncryptionIndex',
            'setExternalAttributesName',
            'setExternalAttributesIndex',
            'getExternalAttributesName',
            'getExternalAttributesIndex',
            'statName',
            'statIndex',
            'setMtimeName',
            'setMtimeIndex',
            'setArchiveFlag',
            'getArchiveFlag',
            'clearError',
            'getStream',
            'getStreamIndex',
            'getStreamName',
            'addGlob',
            'addPattern',
            'registerProgressCallback',
            'registerCancelCallback',
        ] as $zipMethod) {
            $context->functionProxies['ziparchive::'.strtolower($zipMethod)] = new JIT\Call\ZipArchiveMethod(
                $zipMethod
            );
        }

        $context->type->object->registerExternalClassSeeder('ziparchive', static function ($obj, int $id): void {
            // Gate on advertisement (host ext/zip or PHP_COMPILER_ENABLE_ZIP) (#34412 / #28110).
            if (ZipExtensionPolicy::advertisesExtension()) {
                $obj->seedExternalClassConstants($id, ZipArchiveConstants::CLASS_CONSTANTS);
            }
            // Stub props — late defineProperty after new SIGSEGVs (#35002 leftover of #20584).
            $obj->defineProperty($id, VmZipArchive::PROP_STATUS, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, VmZipArchive::PROP_STATUS_SYS, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, VmZipArchive::PROP_LAST_ID, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, VmZipArchive::PROP_NUM_FILES, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, VmZipArchive::PROP_FILENAME, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmZipArchive::PROP_COMMENT, Variable::TYPE_VALUE);
            $obj->defineProperty($id, ZipArchiveJitSupport::PROP_ID, Variable::TYPE_NATIVE_LONG);
            $obj->markHasConstructor($id);
        });
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!ZipExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['zip'];
    }

    public function getFunctions(): array
    {
        if (!ZipExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new zip_open(),
            new zip_close(),
            new zip_read(),
            new zip_entry_open(),
            new zip_entry_close(),
            new zip_entry_read(),
            new zip_entry_name(),
            new zip_entry_filesize(),
            new zip_entry_compressedsize(),
            new zip_entry_compressionmethod(),
        ];
    }
}
