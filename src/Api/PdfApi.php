<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Generated\Operations;
use Kaaljyoti\Models\PdfKundliRequest;
use Kaaljyoti\Models\PdfMatchRequest;
use Kaaljyoti\Models\PdfPanchangMonthRequest;
use Kaaljyoti\Models\PdfVarshphalRequest;
use Kaaljyoti\PdfFile;
use Kaaljyoti\Result;
use Kaaljyoti\Transport;
use UnexpectedValueException;

/**
 * `$kj->pdf` — printable PDFs, answered as the file rather than an envelope.
 *
 * Each takes the JSON route's body plus the PDF's own fields — `template`,
 * `branding`, and for the kundli, match and varshphal `chart_style` and
 * `name` — and answers a {@see PdfFile} with `meta` always `null`. Every paid
 * plan (not Free), secret keys only; the kundli PDF costs 1,000 credits and
 * the others 500, and each uses one PDF from the month's allowance, past which
 * the error is `pdf_quota_exceeded`. `branding` is Enterprise only. `cached`
 * on the result says the 24-hour cache answered (`X-KJ-Cache: hit`): no PDF
 * used, still its credits.
 *
 * A failure is the usual JSON error and throws the same
 * {@see \Kaaljyoti\KaaljyotiException} as everything else, never a PDF.
 */
final readonly class PdfApi extends ApiSection
{
    /**
     * `POST /v1/pdf/kundli` — the birth chart, `basic` (default) or `professional` edition.
     *
     * @return Result<PdfFile, null>
     */
    public function kundli(PdfKundliRequest $request): Result
    {
        return $this->file('postPdfKundli', $request->toArray());
    }

    /**
     * `POST /v1/pdf/match` — the ashtakoot match: the kootas, both charts, mangal dosha.
     *
     * @return Result<PdfFile, null>
     */
    public function match(PdfMatchRequest $request): Result
    {
        return $this->file('postPdfMatch', $request->toArray());
    }

    /**
     * `POST /v1/pdf/varshphal` — the annual chart for `year` and its reading.
     *
     * @return Result<PdfFile, null>
     */
    public function varshphal(PdfVarshphalRequest $request): Result
    {
        return $this->file('postPdfVarshphal', $request->toArray());
    }

    /**
     * `POST /v1/pdf/panchang/month` — a month of panchang at a place, one day per row.
     *
     * `panchangMonth` rather than `month`, because `$kj->pdf->month()` would
     * not say a month of what.
     *
     * @return Result<PdfFile, null>
     */
    public function panchangMonth(PdfPanchangMonthRequest $request): Result
    {
        return $this->file('postPdfPanchangMonth', $request->toArray());
    }

    /**
     * A POST asked for as `application/pdf`.
     *
     * @param array<string, mixed> $body
     * @return Result<PdfFile, null>
     * @throws \Kaaljyoti\KaaljyotiException
     */
    private function file(string $operationId, array $body): Result
    {
        return $this->transport->post(
            Operations::byId($operationId)->path,
            $body,
            // The transport builds the file from the bytes and the headers;
            // this only tells PHPStan what it built.
            static fn (mixed $file): PdfFile => $file instanceof PdfFile
                ? $file
                : throw new UnexpectedValueException('Response was not a PDF'),
            static fn (mixed $json): null => null,
            Transport::ACCEPT_PDF,
        );
    }
}
