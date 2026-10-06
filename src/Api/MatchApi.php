<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Generated\Json;
use Kaaljyoti\Generated\Operations;
use Kaaljyoti\Models\BatchDocument;
use Kaaljyoti\Models\BatchMeta;
use Kaaljyoti\Models\CompareDocument;
use Kaaljyoti\Models\MatchAshtakootDocument;
use Kaaljyoti\Models\MatchAshtakootRequest;
use Kaaljyoti\Models\MatchBatchRequest;
use Kaaljyoti\Models\MatchCompareRequest;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Result;

/**
 * `$kj->match` — ashtakoot, a full comparison, and both in bulk.
 */
final readonly class MatchApi extends ApiSection
{
    /**
     * `POST /v1/match/ashtakoot` — the eight kootas and the total.
     *
     * @return Result<MatchAshtakootDocument, Meta>
     */
    public function ashtakoot(MatchAshtakootRequest $request): Result
    {
        return $this->document('postMatchAshtakoot', $request->toArray(), MatchAshtakootDocument::fromArray(...));
    }

    /**
     * `POST /v1/match/compare` — two charts side by side. Never cached.
     *
     * @return Result<CompareDocument, Meta>
     */
    public function compare(MatchCompareRequest $request): Result
    {
        return $this->document('postMatchCompare', $request->toArray(), CompareDocument::fromArray(...));
    }

    /**
     * `POST /v1/match/batch` — up to 100 pairs, 1 credit each.
     *
     * A pair that could not be answered comes back as `results[i]->error`
     * rather than as a thrown exception: the other pairs were computed and
     * charged, and throwing would discard answers already paid for. Only the
     * whole request failing — a bad key, a body over the 8 KB limit, the rate
     * limit — throws.
     *
     * The `meta` is a `BatchMeta` rather than a `Meta`: there is no single
     * timezone for a hundred pairs. Its `credits` is what the request cost,
     * one per pair.
     *
     * 10 a minute, and closed to publishable keys.
     *
     * @return Result<BatchDocument, BatchMeta>
     */
    public function batch(MatchBatchRequest $request): Result
    {
        return $this->transport->post(
            Operations::byId('postMatchBatch')->path,
            $request->toArray(),
            static fn (mixed $json): BatchDocument => BatchDocument::fromArray(Json::object($json)),
            static fn (mixed $json): BatchMeta => BatchMeta::fromArray(Json::object($json)),
        );
    }
}
