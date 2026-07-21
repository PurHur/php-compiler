<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared gnupg_* semantics (PECL gnupg / gnupg.c; #6668).
 */
final class VmGnupgCore
{
    /**
     * @param array<string, string>|null $options
     */
    public static function init(Context $ctx, ?array $options = null): Variable|false
    {
        if (!VmGnupgNative::available()) {
            return false;
        }
        $nativeCtx = VmGnupgNative::ctxNew();
        if (null === $nativeCtx) {
            return false;
        }
        $var = VmGnupgObject::wrap($ctx, $nativeCtx);
        $object = $var->toObject();
        self::make($object, $options);

        return $var;
    }

    /**
     * @param array<string, string>|null $options
     */
    public static function make(ObjectEntry $object, ?array $options): void
    {
        $st = &VmGnupgObject::state($object);
        if (0 !== $st['err']) {
            return;
        }
        $fileName = null;
        $homeDir = null;
        if (null !== $options) {
            if (isset($options['file_name'])) {
                $fileName = (string) $options['file_name'];
            }
            if (isset($options['home_dir'])) {
                $homeDir = (string) $options['home_dir'];
            }
        }
        if (null !== $fileName || null !== $homeDir) {
            $err = VmGnupgNative::ctxSetEngineInfo(VmGnupgObject::ctx($object), $fileName, $homeDir);
            if (0 !== $err) {
                throw new \Exception('Setting engine info failed');
            }
        }
        VmGnupgNative::setArmor(VmGnupgObject::ctx($object), 1);
        VmGnupgNative::setPinentryModeLoopback(VmGnupgObject::ctx($object));
    }

    public static function addEncryptKey(ObjectEntry $object, string $keyId): bool
    {
        [$key, $err] = VmGnupgNative::getKey(VmGnupgObject::ctx($object), $keyId, false);
        if (0 !== $err || null === $key) {
            VmGnupgObject::reportError($object, 'get_key failed', $err);

            return false;
        }
        VmGnupgObject::state($object)['encrypt_keys'][] = $key;

        return true;
    }

    public static function addDecryptkey(ObjectEntry $object, string $keyId, string $passphrase): bool
    {
        [$key, $err] = VmGnupgNative::getKey(VmGnupgObject::ctx($object), $keyId, true);
        if (0 !== $err || null === $key) {
            VmGnupgObject::reportError($object, 'get_key failed', $err);

            return false;
        }
        self::mapSecretSubkeyPassphrases($object, $key, $passphrase, 'decrypt_keys');
        VmGnupgNative::keyUnref($key);

        return true;
    }

    public static function addSignkey(ObjectEntry $object, string $keyId, ?string $passphrase = null): bool
    {
        [$key, $err] = VmGnupgNative::getKey(VmGnupgObject::ctx($object), $keyId, true);
        if (0 !== $err || null === $key) {
            VmGnupgObject::reportError($object, 'get_key failed', $err);

            return false;
        }
        if (null !== $passphrase) {
            self::mapSigningSubkeyPassphrases($object, $key, $passphrase);
        }
        $addErr = VmGnupgNative::signersAdd(VmGnupgObject::ctx($object), $key);
        VmGnupgNative::keyUnref($key);
        if (0 !== $addErr) {
            VmGnupgObject::reportError($object, 'could not add signer', $addErr);

            return false;
        }

        return true;
    }

    public static function encrypt(ObjectEntry $object, string $text): string|false
    {
        $st = VmGnupgObject::state($object);
        if ([] === $st['encrypt_keys']) {
            VmGnupgObject::reportError($object, 'no key for encryption set');

            return false;
        }
        [$in, $errIn] = VmGnupgNative::dataNewFromMem($text);
        if (0 !== $errIn || null === $in) {
            VmGnupgObject::reportError($object, 'could no create in-data buffer', $errIn);

            return false;
        }
        [$out, $errOut] = VmGnupgNative::dataNew();
        if (0 !== $errOut || null === $out) {
            VmGnupgObject::reportError($object, 'could not create out-data buffer', $errOut);
            VmGnupgNative::dataRelease($in);

            return false;
        }
        $encErr = VmGnupgNative::opEncrypt(
            VmGnupgObject::ctx($object),
            $st['encrypt_keys'],
            $in,
            $out
        );
        VmGnupgNative::dataRelease($in);
        if (0 !== $encErr) {
            VmGnupgObject::reportError($object, 'encrypt failed', $encErr);
            VmGnupgNative::dataRelease($out);

            return false;
        }
        $result = VmGnupgNative::opEncryptResult(VmGnupgObject::ctx($object));
        if (null !== $result && null !== $result->invalid_recipients) {
            VmGnupgObject::reportError($object, 'Invalid recipient encountered');
            VmGnupgNative::dataRelease($out);

            return false;
        }
        $mem = VmGnupgNative::dataReleaseAndGetMem($out);
        if (null === $mem || $mem[1] < 1) {
            return false;
        }

        return $mem[0];
    }

    public static function decrypt(ObjectEntry $object, string $enctext): string|false
    {
        VmGnupgNative::setPassphraseCbDecrypt(
            VmGnupgObject::ctx($object),
            static function (string $uid) use ($object): ?string {
                $map = VmGnupgObject::state($object)['decrypt_keys'];

                return $map[$uid] ?? null;
            }
        );
        [$in, $errIn] = VmGnupgNative::dataNewFromMem($enctext);
        if (0 !== $errIn || null === $in) {
            VmGnupgObject::reportError($object, 'could not create in-data buffer', $errIn);

            return false;
        }
        [$out, $errOut] = VmGnupgNative::dataNew();
        if (0 !== $errOut || null === $out) {
            VmGnupgObject::reportError($object, 'could not create out-data buffer', $errOut);
            VmGnupgNative::dataRelease($in);

            return false;
        }
        $decErr = VmGnupgNative::opDecrypt(VmGnupgObject::ctx($object), $in, $out);
        VmGnupgNative::dataRelease($in);
        if (0 !== $decErr) {
            $st = VmGnupgObject::state($object);
            if (null === $st['errortxt']) {
                VmGnupgObject::reportError($object, 'decrypt failed', $decErr);
            }
            VmGnupgNative::dataRelease($out);

            return false;
        }
        $result = VmGnupgNative::opDecryptResult(VmGnupgObject::ctx($object));
        if (null !== $result && 0 !== (int) $result->unsupported_algorithm) {
            VmGnupgObject::reportError($object, 'unsupported algorithm');
            VmGnupgNative::dataRelease($out);

            return false;
        }
        $mem = VmGnupgNative::dataReleaseAndGetMem($out);
        if (null === $mem) {
            return '';
        }

        return $mem[0];
    }

    public static function sign(ObjectEntry $object, string $text): string|false
    {
        $st = VmGnupgObject::state($object);
        VmGnupgNative::setPassphraseCbSign(
            VmGnupgObject::ctx($object),
            static function (string $uid) use ($object): ?string {
                $map = VmGnupgObject::state($object)['sign_keys'];

                return $map[$uid] ?? null;
            }
        );
        [$in, $errIn] = VmGnupgNative::dataNewFromMem($text);
        if (0 !== $errIn || null === $in) {
            VmGnupgObject::reportError($object, 'could not create in-data buffer', $errIn);

            return false;
        }
        [$out, $errOut] = VmGnupgNative::dataNew();
        if (0 !== $errOut || null === $out) {
            VmGnupgObject::reportError($object, 'could not create out-data buffer', $errOut);
            VmGnupgNative::dataRelease($in);

            return false;
        }
        $signErr = VmGnupgNative::opSign(
            VmGnupgObject::ctx($object),
            $in,
            $out,
            $st['signmode']
        );
        VmGnupgNative::dataRelease($in);
        if (0 !== $signErr) {
            $state = VmGnupgObject::state($object);
            if (null === $state['errortxt']) {
                VmGnupgObject::reportError($object, 'data signing failed', $signErr);
            }
            VmGnupgNative::dataRelease($out);

            return false;
        }
        $result = VmGnupgNative::opSignResult(VmGnupgObject::ctx($object));
        if (null !== $result && null !== $result->invalid_signers) {
            VmGnupgObject::reportError($object, 'invalid signers found');
            VmGnupgNative::dataRelease($out);

            return false;
        }
        if (null !== $result && null === $result->signatures) {
            VmGnupgObject::reportError($object, 'no signature in result');
            VmGnupgNative::dataRelease($out);

            return false;
        }
        $mem = VmGnupgNative::dataReleaseAndGetMem($out);
        if (null === $mem || $mem[1] < 1) {
            return false;
        }

        return $mem[0];
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    public static function verify(
        ObjectEntry $object,
        string $signedText,
        Variable $signatureArg,
        ?Variable $plaintextOut = null
    ): array|false {
        $signatureArg = $signatureArg->resolveIndirect();
        $detached = Variable::TYPE_STRING === $signatureArg->type;
        $gpgmeSig = null;
        $gpgmeText = null;

        if ($detached) {
            [$gpgmeSig, $err] = VmGnupgNative::dataNewFromMem($signatureArg->toString());
            if (0 !== $err || null === $gpgmeSig) {
                VmGnupgObject::reportError($object, 'could not create signature-databuffer', $err);

                return false;
            }
            [$gpgmeText, $err] = VmGnupgNative::dataNewFromMem($signedText);
            if (0 !== $err || null === $gpgmeText) {
                VmGnupgObject::reportError($object, 'could not create text-databuffer', $err);
                VmGnupgNative::dataRelease($gpgmeSig);

                return false;
            }
            $verifyErr = VmGnupgNative::opVerify(
                VmGnupgObject::ctx($object),
                $gpgmeSig,
                $gpgmeText,
                null
            );
        } else {
            [$gpgmeSig, $err] = VmGnupgNative::dataNewFromMem($signedText);
            if (0 !== $err || null === $gpgmeSig) {
                VmGnupgObject::reportError($object, 'could not create signature-databuffer', $err);

                return false;
            }
            [$gpgmeText, $err] = VmGnupgNative::dataNewFromMem('', false);
            if (0 !== $err || null === $gpgmeText) {
                VmGnupgObject::reportError($object, 'could not create text-databuffer', $err);
                VmGnupgNative::dataRelease($gpgmeSig);

                return false;
            }
            $verifyErr = VmGnupgNative::opVerify(
                VmGnupgObject::ctx($object),
                $gpgmeSig,
                null,
                $gpgmeText
            );
        }

        if (0 !== $verifyErr) {
            VmGnupgObject::reportError($object, 'verify failed', $verifyErr);
            VmGnupgNative::dataRelease($gpgmeSig);
            if (null !== $gpgmeText) {
                VmGnupgNative::dataRelease($gpgmeText);
            }

            return false;
        }

        $result = VmGnupgNative::opVerifyResult(VmGnupgObject::ctx($object));
        if (null === $result || null === $result->signatures) {
            VmGnupgObject::reportError($object, 'no signature found');
            VmGnupgNative::dataRelease($gpgmeSig);
            if (null !== $gpgmeText) {
                VmGnupgNative::dataRelease($gpgmeText);
            }

            return false;
        }

        $sigs = self::fetchSignatures($result->signatures);
        if (null !== $plaintextOut && null !== $gpgmeText) {
            $mem = VmGnupgNative::dataReleaseAndGetMem($gpgmeText);
            if (null !== $mem && $mem[1] > 0) {
                $plain = new Variable();
                $plain->string($mem[0]);
                $plaintextOut->byRefTarget()->copyFrom($plain);
            }
            $gpgmeText = null;
        } elseif (null !== $gpgmeText) {
            VmGnupgNative::dataRelease($gpgmeText);
        }
        VmGnupgNative::dataRelease($gpgmeSig);

        return $sigs;
    }

    /**
     * @return list<array<string, mixed>>|false
     */
    public static function keyinfo(ObjectEntry $object, string $pattern, bool $secretOnly = false): array|false
    {
        $err = VmGnupgNative::opKeylistStart(VmGnupgObject::ctx($object), $pattern, $secretOnly);
        if (0 !== $err) {
            VmGnupgObject::reportError($object, 'could not init keylist', $err);

            return false;
        }
        $list = [];
        while (true) {
            [$key, $nextErr] = VmGnupgNative::opKeylistNext(VmGnupgObject::ctx($object));
            if (0 !== $nextErr) {
                break;
            }
            if (null === $key) {
                break;
            }
            $list[] = self::exportKeyInfo($key);
            VmGnupgNative::keyUnref($key);
        }
        VmGnupgNative::opKeylistEnd(VmGnupgObject::ctx($object));

        return $list;
    }

    public static function getError(ObjectEntry $object): string|false
    {
        $txt = VmGnupgObject::state($object)['errortxt'];
        if (null === $txt) {
            return false;
        }

        return $txt;
    }

    /**
     * @return array<string, mixed>
     */
    private static function exportKeyInfo(\FFI\CData $key): array
    {
        $ffi = \FFI::cast('struct gpgme_key*', $key);
        $entry = [
            'disabled' => (bool) (int) $ffi->disabled,
            'expired' => (bool) (int) $ffi->expired,
            'revoked' => (bool) (int) $ffi->revoked,
            'is_secret' => (bool) (int) $ffi->secret,
            'can_sign' => (bool) (int) $ffi->can_sign,
            'can_encrypt' => (bool) (int) $ffi->can_encrypt,
            'uids' => [],
            'subkeys' => [],
        ];
        $uid = $ffi->uids;
        while (null !== $uid) {
            $uidStruct = \FFI::cast('struct gpgme_user_id*', $uid);
            $entry['uids'][] = [
                'name' => self::cString($uidStruct->name),
                'comment' => self::cString($uidStruct->comment),
                'email' => self::cString($uidStruct->email),
                'uid' => self::cString($uidStruct->uid),
                'revoked' => (bool) (int) $uidStruct->revoked,
                'invalid' => (bool) (int) $uidStruct->invalid,
            ];
            $uid = $uidStruct->next;
        }
        $sub = $ffi->subkeys;
        while (null !== $sub) {
            $subStruct = \FFI::cast('struct gpgme_subkey*', $sub);
            $sk = [
                'fingerprint' => self::cString($subStruct->fpr),
                'keyid' => self::cString($subStruct->keyid),
                'timestamp' => (int) $subStruct->timestamp,
                'expires' => (int) $subStruct->expires,
                'is_secret' => (bool) (int) $subStruct->secret,
                'invalid' => (bool) (int) $subStruct->invalid,
                'can_encrypt' => (bool) (int) $subStruct->can_encrypt,
                'can_sign' => (bool) (int) $subStruct->can_sign,
                'disabled' => (bool) (int) $subStruct->disabled,
                'expired' => (bool) (int) $subStruct->expired,
                'revoked' => (bool) (int) $subStruct->revoked,
                'can_certify' => (bool) (int) $subStruct->can_certify,
                'can_authenticate' => (bool) (int) $subStruct->can_authenticate,
                'is_qualified' => (bool) (int) $subStruct->is_qualified,
                'pubkey_algo' => (int) $subStruct->pubkey_algo,
                'length' => (int) $subStruct->length,
                'is_cardkey' => (bool) (int) $subStruct->is_cardkey,
            ];
            if (null !== $subStruct->keygrip) {
                $sk['keygrip'] = self::cString($subStruct->keygrip);
            }
            if (null !== $subStruct->curve) {
                $sk['curve'] = self::cString($subStruct->curve);
            }
            if (null !== $subStruct->card_number) {
                $sk['card_number'] = self::cString($subStruct->card_number);
            }
            $entry['subkeys'][] = $sk;
            $sub = $subStruct->next;
        }

        return $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchSignatures(\FFI\CData $head): array
    {
        $out = [];
        $sig = $head;
        while (null !== $sig) {
            $out[] = [
                'fingerprint' => self::cString($sig->fpr),
                'validity' => (int) $sig->validity,
                'timestamp' => (int) $sig->timestamp,
                'status' => (int) $sig->status,
                'summary' => (int) $sig->summary,
            ];
            $sig = $sig->next;
        }

        return $out;
    }

    private static function mapSecretSubkeyPassphrases(
        ObjectEntry $object,
        \FFI\CData $key,
        string $passphrase,
        string $mapField
    ): void {
        $ffi = \FFI::cast('struct gpgme_key*', $key);
        $sub = $ffi->subkeys;
        $st = &VmGnupgObject::state($object);
        while (null !== $sub) {
            $subStruct = \FFI::cast('struct gpgme_subkey*', $sub);
            if (0 !== (int) $subStruct->secret) {
                $kid = self::cString($subStruct->keyid);
                if ('' !== $kid) {
                    $st[$mapField][$kid] = $passphrase;
                }
            }
            $sub = $subStruct->next;
        }
    }

    private static function mapSigningSubkeyPassphrases(
        ObjectEntry $object,
        \FFI\CData $key,
        string $passphrase
    ): void {
        $ffi = \FFI::cast('struct gpgme_key*', $key);
        $sub = $ffi->subkeys;
        $st = &VmGnupgObject::state($object);
        while (null !== $sub) {
            $subStruct = \FFI::cast('struct gpgme_subkey*', $sub);
            if (0 !== (int) $subStruct->can_sign) {
                $kid = self::cString($subStruct->keyid);
                if ('' !== $kid) {
                    $st['sign_keys'][$kid] = $passphrase;
                }
            }
            $sub = $subStruct->next;
        }
    }

    private static function cString(?\FFI\CData $ptr): string
    {
        if (null === $ptr) {
            return '';
        }

        return (string) $ptr;
    }
}
