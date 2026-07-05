/**
 * Thin libcrypto EVP sign/verify for AOT/JIT (#3324).
 *
 * PHP semantics live in ext/openssl/VmOpenssl.php (VM). These symbols satisfy
 * __compiler_openssl_sign / __compiler_openssl_verify LLVM ABI for standalone
 * link when PHP FFI is unavailable in the AOT binary.
 *
 * php-src: ext/openssl/openssl.c
 */
#include <openssl/evp.h>
#include <openssl/pem.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef struct {
    void *ref;
    int64_t length;
    char value;
} phpc_string;

typedef struct {
    int8_t type;
    int8_t padding[7];
    phpc_string *string;
} phpc_value;

extern phpc_string *__string__init(int64_t size, const char *value);

static const char *phpc_openssl_algo_name(int32_t algo)
{
    switch (algo) {
        case 1: return "sha1";
        case 2: return "md5";
        case 3: return "md4";
        case 6: return "sha224";
        case 7: return "sha256";
        case 8: return "sha384";
        case 9: return "sha512";
        case 10: return "ripemd160";
        default: return NULL;
    }
}

static EVP_PKEY *phpc_read_private_key(const char *pem, size_t pem_len)
{
    BIO *bio = BIO_new_mem_buf(pem, (int) pem_len);
    if (NULL == bio) {
        return NULL;
    }
    EVP_PKEY *pkey = PEM_read_bio_PrivateKey(bio, NULL, NULL, NULL);
    BIO_free(bio);

    return pkey;
}

static EVP_PKEY *phpc_read_public_key(const char *pem, size_t pem_len)
{
    BIO *bio = BIO_new_mem_buf(pem, (int) pem_len);
    if (NULL == bio) {
        return NULL;
    }
    EVP_PKEY *pkey = PEM_read_bio_PUBKEY(bio, NULL, NULL, NULL);
    if (NULL == pkey) {
        pkey = PEM_read_bio_PrivateKey(bio, NULL, NULL, NULL);
    }
    BIO_free(bio);

    return pkey;
}

static phpc_string *phpc_string_from_bytes(const unsigned char *buf, size_t len)
{
    if (NULL == buf || 0 == len) {
        return NULL;
    }

    return __string__init((int64_t) len, (const char *) buf);
}

phpc_string *__compiler_openssl_sign(phpc_string *data, phpc_string *key_pem, int64_t algo)
{
    if (NULL == data || NULL == key_pem) {
        return NULL;
    }
    const char *digest = phpc_openssl_algo_name((int32_t) algo);
    if (NULL == digest) {
        return NULL;
    }

    EVP_PKEY *pkey = phpc_read_private_key(&key_pem->value, (size_t) key_pem->length);
    if (NULL == pkey) {
        return NULL;
    }

    const EVP_MD *md = EVP_get_digestbyname(digest);
    if (NULL == md) {
        EVP_PKEY_free(pkey);

        return NULL;
    }

    EVP_MD_CTX *ctx = EVP_MD_CTX_new();
    if (NULL == ctx) {
        EVP_PKEY_free(pkey);

        return NULL;
    }

    phpc_string *result = NULL;
    if (1 == EVP_DigestSignInit(ctx, NULL, md, NULL, pkey)
        && 1 == EVP_DigestSignUpdate(ctx, &data->value, (size_t) data->length)) {
        size_t siglen = 0;
        if (1 == EVP_DigestSignFinal(ctx, NULL, &siglen) && siglen > 0) {
            unsigned char *sig = (unsigned char *) malloc(siglen);
            if (NULL != sig && 1 == EVP_DigestSignFinal(ctx, sig, &siglen)) {
                result = phpc_string_from_bytes(sig, siglen);
            }
            free(sig);
        }
    }

    EVP_MD_CTX_free(ctx);
    EVP_PKEY_free(pkey);

    return result;
}

int32_t __compiler_openssl_verify(
    phpc_string *data,
    phpc_string *signature,
    phpc_string *key_pem,
    int64_t algo
)
{
    if (NULL == data || NULL == signature || NULL == key_pem) {
        return -1;
    }
    const char *digest = phpc_openssl_algo_name((int32_t) algo);
    if (NULL == digest) {
        return -1;
    }

    EVP_PKEY *pkey = phpc_read_public_key(&key_pem->value, (size_t) key_pem->length);
    if (NULL == pkey) {
        return -1;
    }

    const EVP_MD *md = EVP_get_digestbyname(digest);
    if (NULL == md) {
        EVP_PKEY_free(pkey);

        return -1;
    }

    EVP_MD_CTX *ctx = EVP_MD_CTX_new();
    if (NULL == ctx) {
        EVP_PKEY_free(pkey);

        return -1;
    }

    int32_t result = -1;
    if (1 == EVP_DigestVerifyInit(ctx, NULL, md, NULL, pkey)
        && 1 == EVP_DigestVerifyUpdate(ctx, &data->value, (size_t) data->length)) {
        int rc = EVP_DigestVerifyFinal(ctx, (unsigned char *) &signature->value, (size_t) signature->length);
        if (1 == rc) {
            result = 1;
        } else if (0 == rc) {
            result = 0;
        }
    }

    EVP_MD_CTX_free(ctx);
    EVP_PKEY_free(pkey);

    return result;
}
