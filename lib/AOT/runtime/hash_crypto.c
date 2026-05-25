/*
 * hash() / hash_hmac() runtime for AOT/JIT (issue #179).
 * Algorithms: sha256, sha1, md5 — no OpenSSL dependency.
 */

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static size_t hc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *hc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int hc_eq_ci(const char *a, size_t alen, const char *b)
{
    size_t blen = strlen(b);

    if (alen != blen) {
        return 0;
    }
    for (size_t i = 0; i < alen; i++) {
        char ca = a[i];
        char cb = b[i];
        if (ca >= 'A' && ca <= 'Z') {
            ca = (char) (ca + 32);
        }
        if (cb >= 'A' && cb <= 'Z') {
            cb = (char) (cb + 32);
        }
        if (ca != cb) {
            return 0;
        }
    }

    return 1;
}

/** 0 unknown, 1 sha256, 2 sha1, 3 md5 */
static int hc_algo_id(__string__ *algo)
{
    const char *name = hc_strdata(algo);
    size_t len = hc_strlen(algo);

    if (hc_eq_ci(name, len, "sha256")) {
        return 1;
    }
    if (hc_eq_ci(name, len, "sha1")) {
        return 2;
    }
    if (hc_eq_ci(name, len, "md5")) {
        return 3;
    }

    return 0;
}

static void hc_hex_encode(const unsigned char *bin, size_t bin_len, char *out)
{
    static const char hex[] = "0123456789abcdef";

    for (size_t i = 0; i < bin_len; i++) {
        out[i * 2] = hex[(bin[i] >> 4) & 0x0f];
        out[i * 2 + 1] = hex[bin[i] & 0x0f];
    }
    out[bin_len * 2] = '\0';
}

/* --- SHA-256 --- */
#define SHA256_BLOCK_SIZE 64
#define SHA256_DIGEST_SIZE 32

typedef struct {
    uint8_t data[64];
    uint32_t datalen;
    uint64_t bitlen;
    uint32_t state[8];
} hc_sha256_ctx;

static const uint32_t hc_sha256_k[64] = {
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
};

#define HC_ROTR(x, n) (((x) >> (n)) | ((x) << (32 - (n))))
#define HC_CH(x, y, z) (((x) & (y)) ^ (~(x) & (z)))
#define HC_MAJ(x, y, z) (((x) & (y)) ^ ((x) & (z)) ^ ((y) & (z)))
#define HC_EP0(x) (HC_ROTR(x, 2) ^ HC_ROTR(x, 13) ^ HC_ROTR(x, 22))
#define HC_EP1(x) (HC_ROTR(x, 6) ^ HC_ROTR(x, 11) ^ HC_ROTR(x, 25))
#define HC_SIG0(x) (HC_ROTR(x, 7) ^ HC_ROTR(x, 18) ^ ((x) >> 3))
#define HC_SIG1(x) (HC_ROTR(x, 17) ^ HC_ROTR(x, 19) ^ ((x) >> 10))

static void hc_sha256_transform(hc_sha256_ctx *ctx, const uint8_t data[])
{
    uint32_t m[64];
    uint32_t a, b, c, d, e, f, g, h, t1, t2;

    for (int i = 0, j = 0; i < 16; ++i, j += 4) {
        m[i] = ((uint32_t) data[j] << 24) | ((uint32_t) data[j + 1] << 16)
            | ((uint32_t) data[j + 2] << 8) | ((uint32_t) data[j + 3]);
    }
    for (int i = 16; i < 64; ++i) {
        m[i] = HC_SIG1(m[i - 2]) + m[i - 7] + HC_SIG0(m[i - 15]) + m[i - 16];
    }
    a = ctx->state[0];
    b = ctx->state[1];
    c = ctx->state[2];
    d = ctx->state[3];
    e = ctx->state[4];
    f = ctx->state[5];
    g = ctx->state[6];
    h = ctx->state[7];
    for (int i = 0; i < 64; ++i) {
        t1 = h + HC_EP1(e) + HC_CH(e, f, g) + hc_sha256_k[i] + m[i];
        t2 = HC_EP0(a) + HC_MAJ(a, b, c);
        h = g;
        g = f;
        f = e;
        e = d + t1;
        d = c;
        c = b;
        b = a;
        a = t1 + t2;
    }
    ctx->state[0] += a;
    ctx->state[1] += b;
    ctx->state[2] += c;
    ctx->state[3] += d;
    ctx->state[4] += e;
    ctx->state[5] += f;
    ctx->state[6] += g;
    ctx->state[7] += h;
}

static void hc_sha256_init(hc_sha256_ctx *ctx)
{
    ctx->datalen = 0;
    ctx->bitlen = 0;
    ctx->state[0] = 0x6a09e667;
    ctx->state[1] = 0xbb67ae85;
    ctx->state[2] = 0x3c6ef372;
    ctx->state[3] = 0xa54ff53a;
    ctx->state[4] = 0x510e527f;
    ctx->state[5] = 0x9b05688c;
    ctx->state[6] = 0x1f83d9ab;
    ctx->state[7] = 0x5be0cd19;
}

static void hc_sha256_update(hc_sha256_ctx *ctx, const uint8_t data[], size_t len)
{
    for (size_t i = 0; i < len; ++i) {
        ctx->data[ctx->datalen] = data[i];
        ctx->datalen++;
        if (ctx->datalen == 64) {
            hc_sha256_transform(ctx, ctx->data);
            ctx->bitlen += 512;
            ctx->datalen = 0;
        }
    }
}

static void hc_sha256_final(hc_sha256_ctx *ctx, uint8_t hash[])
{
    uint32_t datalen = ctx->datalen;
    uint8_t padlen;
    uint32_t i;

    ctx->bitlen += ((uint64_t) datalen) * 8;

    if (datalen < 56) {
        padlen = (uint8_t) (56 - datalen);
    } else {
        padlen = (uint8_t) (120 - datalen);
    }
    uint8_t pad[128];
    memset(pad, 0, sizeof(pad));
    pad[0] = 0x80;
    hc_sha256_update(ctx, pad, padlen);
    uint8_t bits[8];
    for (i = 0; i < 8; i++) {
        bits[i] = (uint8_t) ((ctx->bitlen >> (56 - i * 8)) & 0xff);
    }
    hc_sha256_update(ctx, bits, 8);
    for (i = 0; i < 4; ++i) {
        hash[i] = (uint8_t) ((ctx->state[0] >> (24 - i * 8)) & 0xff);
        hash[i + 4] = (uint8_t) ((ctx->state[1] >> (24 - i * 8)) & 0xff);
        hash[i + 8] = (uint8_t) ((ctx->state[2] >> (24 - i * 8)) & 0xff);
        hash[i + 12] = (uint8_t) ((ctx->state[3] >> (24 - i * 8)) & 0xff);
        hash[i + 16] = (uint8_t) ((ctx->state[4] >> (24 - i * 8)) & 0xff);
        hash[i + 20] = (uint8_t) ((ctx->state[5] >> (24 - i * 8)) & 0xff);
        hash[i + 24] = (uint8_t) ((ctx->state[6] >> (24 - i * 8)) & 0xff);
        hash[i + 28] = (uint8_t) ((ctx->state[7] >> (24 - i * 8)) & 0xff);
    }
}

/* --- MD5 (RFC 1321 subset) --- */
#define MD5_DIGEST_SIZE 16

typedef struct {
    uint32_t count[2];
    uint32_t state[4];
    uint8_t buffer[64];
} hc_md5_ctx;

#define HC_F(x, y, z) (((x) & (y)) | (~(x) & (z)))
#define HC_G(x, y, z) (((x) & (z)) | ((y) & (~(z))))
#define HC_H(x, y, z) ((x) ^ (y) ^ (z))
#define HC_I(x, y, z) ((y) ^ ((x) | (~(z))))

static void hc_md5_encode(uint8_t *output, const uint32_t *input, size_t len)
{
    for (size_t i = 0, j = 0; j < len; i++, j += 4) {
        output[j] = (uint8_t) (input[i] & 0xff);
        output[j + 1] = (uint8_t) ((input[i] >> 8) & 0xff);
        output[j + 2] = (uint8_t) ((input[i] >> 16) & 0xff);
        output[j + 3] = (uint8_t) ((input[i] >> 24) & 0xff);
    }
}

static void hc_md5_decode(uint32_t *output, const uint8_t *input, size_t len)
{
    for (size_t i = 0, j = 0; j < len; i++, j += 4) {
        output[i] = ((uint32_t) input[j]) | (((uint32_t) input[j + 1]) << 8)
            | (((uint32_t) input[j + 2]) << 16) | (((uint32_t) input[j + 3]) << 24);
    }
}

static void hc_md5_transform(uint32_t state[4], const uint8_t block[64])
{
    uint32_t a = state[0], b = state[1], c = state[2], d = state[3], x[16];

    hc_md5_decode(x, block, 64);
#define HC_FF(a, b, c, d, x, s, ac) \
    { \
        (a) += HC_F((b), (c), (d)) + (x) + (uint32_t)(ac); \
        (a) = (((a) << (s)) | ((a) >> (32 - (s)))) + (b); \
    }
#define HC_GG(a, b, c, d, x, s, ac) \
    { \
        (a) += HC_G((b), (c), (d)) + (x) + (uint32_t)(ac); \
        (a) = (((a) << (s)) | ((a) >> (32 - (s)))) + (b); \
    }
#define HC_HH(a, b, c, d, x, s, ac) \
    { \
        (a) += HC_H((b), (c), (d)) + (x) + (uint32_t)(ac); \
        (a) = (((a) << (s)) | ((a) >> (32 - (s)))) + (b); \
    }
#define HC_II(a, b, c, d, x, s, ac) \
    { \
        (a) += HC_I((b), (c), (d)) + (x) + (uint32_t)(ac); \
        (a) = (((a) << (s)) | ((a) >> (32 - (s)))) + (b); \
    }
    HC_FF(a, b, c, d, x[0], 7, 0xd76aa478);
    HC_FF(d, a, b, c, x[1], 12, 0xe8c7b756);
    HC_FF(c, d, a, b, x[2], 17, 0x242070db);
    HC_FF(b, c, d, a, x[3], 22, 0xc1bdceee);
    HC_FF(a, b, c, d, x[4], 7, 0xf57c0faf);
    HC_FF(d, a, b, c, x[5], 12, 0x4787c62a);
    HC_FF(c, d, a, b, x[6], 17, 0xa8304613);
    HC_FF(b, c, d, a, x[7], 22, 0xfd469501);
    HC_FF(a, b, c, d, x[8], 7, 0x698098d8);
    HC_FF(d, a, b, c, x[9], 12, 0x8b44f7af);
    HC_FF(c, d, a, b, x[10], 17, 0xffff5bb1);
    HC_FF(b, c, d, a, x[11], 22, 0x895cd7be);
    HC_FF(a, b, c, d, x[12], 7, 0x6b901122);
    HC_FF(d, a, b, c, x[13], 12, 0xfd987193);
    HC_FF(c, d, a, b, x[14], 17, 0xa679438e);
    HC_FF(b, c, d, a, x[15], 22, 0x49b40821);
    HC_GG(a, b, c, d, x[1], 5, 0xf61e2562);
    HC_GG(d, a, b, c, x[6], 9, 0xc040b340);
    HC_GG(c, d, a, b, x[11], 14, 0x265e5a51);
    HC_GG(b, c, d, a, x[0], 20, 0xe9b6c7aa);
    HC_GG(a, b, c, d, x[5], 5, 0xd62f105d);
    HC_GG(d, a, b, c, x[10], 9, 0x02441453);
    HC_GG(c, d, a, b, x[15], 14, 0xd8a1e681);
    HC_GG(b, c, d, a, x[4], 20, 0xe7d3fbc8);
    HC_GG(a, b, c, d, x[9], 5, 0x21e1cde6);
    HC_GG(d, a, b, c, x[14], 9, 0xc33707d6);
    HC_GG(c, d, a, b, x[3], 14, 0xf4d50d87);
    HC_GG(b, c, d, a, x[8], 20, 0x455a14ed);
    HC_GG(a, b, c, d, x[13], 5, 0xa9e3e905);
    HC_GG(d, a, b, c, x[2], 9, 0xfcefa3f8);
    HC_GG(c, d, a, b, x[7], 14, 0x676f02d9);
    HC_GG(b, c, d, a, x[12], 20, 0x8d2a4c8a);
    HC_HH(a, b, c, d, x[5], 4, 0xfffa3942);
    HC_HH(d, a, b, c, x[8], 11, 0x8771f681);
    HC_HH(c, d, a, b, x[11], 16, 0x6d9d6122);
    HC_HH(b, c, d, a, x[14], 23, 0xfde5380c);
    HC_HH(a, b, c, d, x[1], 4, 0xa4beea44);
    HC_HH(d, a, b, c, x[4], 11, 0x4bdecfa9);
    HC_HH(c, d, a, b, x[7], 16, 0xf6bb4b60);
    HC_HH(b, c, d, a, x[10], 23, 0xbebfbc70);
    HC_HH(a, b, c, d, x[13], 4, 0x289b7ec6);
    HC_HH(d, a, b, c, x[0], 11, 0xeaa127fa);
    HC_HH(c, d, a, b, x[3], 16, 0xd4ef3085);
    HC_HH(b, c, d, a, x[6], 23, 0x04881d05);
    HC_HH(a, b, c, d, x[9], 4, 0xd9d4d039);
    HC_HH(d, a, b, c, x[12], 11, 0xe6db99e5);
    HC_HH(c, d, a, b, x[15], 16, 0x1fa27cf8);
    HC_HH(b, c, d, a, x[2], 23, 0xc4ac5665);
    HC_II(a, b, c, d, x[0], 6, 0xf4292244);
    HC_II(d, a, b, c, x[7], 10, 0x432aff97);
    HC_II(c, d, a, b, x[14], 15, 0xab9423a7);
    HC_II(b, c, d, a, x[5], 21, 0xfc93a039);
    HC_II(a, b, c, d, x[12], 6, 0x655b59c3);
    HC_II(d, a, b, c, x[3], 10, 0x8f0ccc92);
    HC_II(c, d, a, b, x[10], 15, 0xffeff47d);
    HC_II(b, c, d, a, x[1], 21, 0x85845dd1);
    HC_II(a, b, c, d, x[8], 6, 0x6fa87e4f);
    HC_II(d, a, b, c, x[15], 10, 0xfe2ce6e0);
    HC_II(c, d, a, b, x[6], 15, 0xa3014314);
    HC_II(b, c, d, a, x[13], 21, 0x4e0811a1);
    HC_II(a, b, c, d, x[4], 6, 0xf7537e82);
    HC_II(d, a, b, c, x[11], 10, 0xbd3af235);
    HC_II(c, d, a, b, x[2], 15, 0x2ad7d2bb);
    HC_II(b, c, d, a, x[9], 21, 0xeb86d391);
    state[0] += a;
    state[1] += b;
    state[2] += c;
    state[3] += d;
#undef HC_FF
#undef HC_GG
#undef HC_HH
#undef HC_II
}

static void hc_md5_init(hc_md5_ctx *ctx)
{
    ctx->count[0] = ctx->count[1] = 0;
    ctx->state[0] = 0x67452301;
    ctx->state[1] = 0xefcdab89;
    ctx->state[2] = 0x98badcfe;
    ctx->state[3] = 0x10325476;
}

static void hc_md5_update(hc_md5_ctx *ctx, const uint8_t *input, size_t len)
{
    size_t i, index, partLen;
    index = (size_t) ((ctx->count[0] >> 3) & 0x3f);
    if ((ctx->count[0] += ((uint32_t) len << 3)) < ((uint32_t) len << 3)) {
        ctx->count[1]++;
    }
    ctx->count[1] += ((uint32_t) len >> 29);
    partLen = 64 - index;
    if (len >= partLen) {
        memcpy(&ctx->buffer[index], input, partLen);
        hc_md5_transform(ctx->state, ctx->buffer);
        for (i = partLen; i + 63 < len; i += 64) {
            hc_md5_transform(ctx->state, &input[i]);
        }
        index = 0;
    } else {
        i = 0;
    }
    memcpy(&ctx->buffer[index], &input[i], len - i);
}

static void hc_md5_final(uint8_t digest[16], hc_md5_ctx *ctx)
{
    uint8_t bits[8];
    size_t index, padLen;
    static const uint8_t PADDING[64] = {0x80};

    hc_md5_encode(bits, ctx->count, 8);
    index = (size_t) ((ctx->count[0] >> 3) & 0x3f);
    padLen = (index < 56) ? (56 - index) : (120 - index);
    hc_md5_update(ctx, PADDING, padLen);
    hc_md5_update(ctx, bits, 8);
    hc_md5_encode(digest, ctx->state, 16);
}

/* --- SHA-1 --- */
#define SHA1_DIGEST_SIZE 20

typedef struct {
    uint32_t state[5];
    uint32_t count[2];
    uint8_t buffer[64];
} hc_sha1_ctx;

#define HC_SHA1_ROL(value, bits) (((value) << (bits)) | ((value) >> (32 - (bits))))

static void hc_sha1_transform(hc_sha1_ctx *context, const uint8_t buffer[64])
{
    uint32_t a, b, c, d, e, w[80];

    hc_md5_decode(w, buffer, 64);
    for (int i = 0; i < 16; i++) {
        w[i] = ((w[i] << 24) & 0xff000000) | ((w[i] << 8) & 0x00ff0000)
            | ((w[i] >> 8) & 0x0000ff00) | ((w[i] >> 24) & 0x000000ff);
    }
    for (int i = 16; i < 80; i++) {
        w[i] = HC_SHA1_ROL(w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16], 1);
    }
    a = context->state[0];
    b = context->state[1];
    c = context->state[2];
    d = context->state[3];
    e = context->state[4];
    for (int i = 0; i < 20; i++) {
        uint32_t f = (b & c) | ((~b) & d);
        uint32_t temp = HC_SHA1_ROL(a, 5) + f + e + w[i] + 0x5A827999;
        e = d;
        d = c;
        c = HC_SHA1_ROL(b, 30);
        b = a;
        a = temp;
    }
    for (int i = 20; i < 40; i++) {
        uint32_t f = b ^ c ^ d;
        uint32_t temp = HC_SHA1_ROL(a, 5) + f + e + w[i] + 0x6ED9EBA1;
        e = d;
        d = c;
        c = HC_SHA1_ROL(b, 30);
        b = a;
        a = temp;
    }
    for (int i = 40; i < 60; i++) {
        uint32_t f = (b & c) | (b & d) | (c & d);
        uint32_t temp = HC_SHA1_ROL(a, 5) + f + e + w[i] + 0x8F1BBCDC;
        e = d;
        d = c;
        c = HC_SHA1_ROL(b, 30);
        b = a;
        a = temp;
    }
    for (int i = 60; i < 80; i++) {
        uint32_t f = b ^ c ^ d;
        uint32_t temp = HC_SHA1_ROL(a, 5) + f + e + w[i] + 0xCA62C1D6;
        e = d;
        d = c;
        c = HC_SHA1_ROL(b, 30);
        b = a;
        a = temp;
    }
    context->state[0] += a;
    context->state[1] += b;
    context->state[2] += c;
    context->state[3] += d;
    context->state[4] += e;
}

static void hc_sha1_init(hc_sha1_ctx *context)
{
    context->state[0] = 0x67452301;
    context->state[1] = 0xEFCDAB89;
    context->state[2] = 0x98BADCFE;
    context->state[3] = 0x10325476;
    context->state[4] = 0xC3D2E1F0;
    context->count[0] = context->count[1] = 0;
}

static void hc_sha1_update(hc_sha1_ctx *context, const uint8_t *data, size_t len)
{
    size_t i, j;
    j = (size_t) ((context->count[0] >> 3) & 63);
    if ((context->count[0] += (uint32_t) (len << 3)) < (len << 3)) {
        context->count[1]++;
    }
    context->count[1] += (uint32_t) (len >> 29);
    if ((j + len) > 63) {
        memcpy(&context->buffer[j], data, (i = 64 - j));
        hc_sha1_transform(context, context->buffer);
        for (; i + 63 < len; i += 64) {
            hc_sha1_transform(context, &data[i]);
        }
        j = 0;
    } else {
        i = 0;
    }
    memcpy(&context->buffer[j], &data[i], len - i);
}

static void hc_sha1_final(uint8_t digest[20], hc_sha1_ctx *context)
{
    uint8_t finalcount[8];
    uint8_t c;
    size_t i, j;
    for (i = 0; i < 8; i++) {
        finalcount[i] = (uint8_t) ((context->count[(i >= 4 ? 0 : 1)] >> ((3 - (i & 3)) * 8)) & 255);
    }
    c = 0x80;
    hc_sha1_update(context, &c, 1);
    while ((context->count[0] & 504) != 448) {
        c = 0x00;
        hc_sha1_update(context, &c, 1);
    }
    hc_sha1_update(context, finalcount, 8);
    for (i = 0; i < 20; i++) {
        digest[i] = (uint8_t) ((context->state[i >> 2] >> ((3 - (i & 3)) * 8)) & 255);
    }
}

static size_t hc_digest_len(int algo)
{
    if (1 == algo) {
        return SHA256_DIGEST_SIZE;
    }
    if (2 == algo) {
        return SHA1_DIGEST_SIZE;
    }

    return MD5_DIGEST_SIZE;
}

static void hc_digest(int algo, const uint8_t *data, size_t len, uint8_t *out)
{
    if (1 == algo) {
        hc_sha256_ctx ctx;
        hc_sha256_init(&ctx);
        hc_sha256_update(&ctx, data, len);
        hc_sha256_final(&ctx, out);

        return;
    }
    if (2 == algo) {
        hc_sha1_ctx ctx;
        hc_sha1_init(&ctx);
        hc_sha1_update(&ctx, data, len);
        hc_sha1_final(out, &ctx);

        return;
    }
    {
        hc_md5_ctx ctx;
        hc_md5_init(&ctx);
        hc_md5_update(&ctx, data, len);
        hc_md5_final(out, &ctx);
    }
}

static __string__ *hc_result_string(int algo, const uint8_t *digest, int raw)
{
    size_t dlen = hc_digest_len(algo);

    if (raw) {
        return __string__init((long long) dlen, (const char *) digest);
    }
    char *hex = (char *) malloc(dlen * 2 + 1);
    if (NULL == hex) {
        return NULL;
    }
    hc_hex_encode(digest, dlen, hex);
    __string__ *result = __string__init((long long) (dlen * 2), hex);
    free(hex);

    return result;
}

static void hc_hmac(int algo, const uint8_t *data, size_t data_len,
    const uint8_t *key, size_t key_len, uint8_t *out)
{
    uint8_t k_pad[64];
    uint8_t tk[SHA256_DIGEST_SIZE];
    uint8_t inner[SHA256_DIGEST_SIZE];
    size_t dlen = hc_digest_len(algo);
    size_t i;

    memset(k_pad, 0, sizeof(k_pad));
    if (key_len > 64) {
        hc_digest(algo, key, key_len, tk);
        memcpy(k_pad, tk, dlen);
    } else {
        memcpy(k_pad, key, key_len);
    }
    for (i = 0; i < 64; i++) {
        k_pad[i] ^= 0x36;
    }
    {
        size_t inner_len = 64 + data_len;
        uint8_t *buf = (uint8_t *) malloc(inner_len);
        if (NULL == buf) {
            return;
        }
        memcpy(buf, k_pad, 64);
        memcpy(buf + 64, data, data_len);
        hc_digest(algo, buf, inner_len, inner);
        free(buf);
    }
    for (i = 0; i < 64; i++) {
        k_pad[i] ^= (0x36 ^ 0x5c);
    }
    {
        size_t outer_len = 64 + dlen;
        uint8_t *buf = (uint8_t *) malloc(outer_len);
        if (NULL == buf) {
            return;
        }
        memcpy(buf, k_pad, 64);
        memcpy(buf + 64, inner, dlen);
        hc_digest(algo, buf, outer_len, out);
        free(buf);
    }
}

__string__ *__compiler_hash(__string__ *algo, __string__ *data, int raw)
{
    int id = hc_algo_id(algo);

    if (0 == id) {
        return NULL;
    }
    const char *bytes = hc_strdata(data);
    size_t len = hc_strlen(data);
    uint8_t digest[SHA256_DIGEST_SIZE];

    hc_digest(id, (const uint8_t *) bytes, len, digest);

    return hc_result_string(id, digest, raw);
}

__string__ *__compiler_hash_hmac(__string__ *algo, __string__ *data, __string__ *key, int raw)
{
    int id = hc_algo_id(algo);

    if (0 == id) {
        return NULL;
    }
    uint8_t digest[SHA256_DIGEST_SIZE];

    hc_hmac(
        id,
        (const uint8_t *) hc_strdata(data),
        hc_strlen(data),
        (const uint8_t *) hc_strdata(key),
        hc_strlen(key),
        digest
    );

    return hc_result_string(id, digest, raw);
}

/** Timing-safe string compare for hash_equals() (issue #2179). */
int __compiler_hash_equals(__string__ *known, __string__ *user)
{
    size_t known_len = hc_strlen(known);
    size_t user_len = hc_strlen(user);
    const unsigned char *ka = (const unsigned char *) hc_strdata(known);
    const unsigned char *ua = (const unsigned char *) hc_strdata(user);
    size_t len = known_len;
    int result = 0;

    if (known_len != user_len) {
        return 0;
    }
    for (size_t i = 0; i < len; i++) {
        result |= ka[i] ^ ua[i];
    }

    return result == 0 ? 1 : 0;
}
