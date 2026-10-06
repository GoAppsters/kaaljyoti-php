<?php

declare(strict_types=1);

namespace Kaaljyoti;

/**
 * A PDF, as the `/v1/pdf/*` routes answer one: the bytes and what the headers
 * say about them.
 *
 * ```php
 * $file = $kj->pdf->kundli($request)->data;
 * file_put_contents($file->filename ?? 'kundli.pdf', $file->bytes);
 * ```
 *
 * `bytes` is a PHP string, which is a byte string: nothing on the way here
 * decodes or re-encodes it, so it is the file exactly as the gateway sent it.
 */
final readonly class PdfFile
{
    /**
     * @param string $bytes The file itself, ready for `file_put_contents()` or a download response.
     * @param string $contentType `Content-Type`: `application/pdf`.
     * @param string|null $filename From `Content-Disposition`: `kundli-ravi-kumar.pdf`, or after the date,
     *     year or month when the body had no `name`. `null` without the header.
     * @param int|null $credits `X-KJ-Credits`: what the PDF cost (1,000 for a kundli, 500 for the others), or
     *     `null` without the header.
     */
    public function __construct(
        public string $bytes,
        public string $contentType = 'application/pdf',
        public ?string $filename = null,
        public ?int $credits = null,
    ) {
    }
}
