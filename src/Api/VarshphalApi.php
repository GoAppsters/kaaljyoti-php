<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Models\Meta;
use Kaaljyoti\Models\VarshphalHarshaBalaDocument;
use Kaaljyoti\Models\VarshphalMuddaDocument;
use Kaaljyoti\Models\VarshphalRequest;
use Kaaljyoti\Models\VarshphalSahamsDocument;
use Kaaljyoti\Models\VarshphalTajikaDocument;
use Kaaljyoti\Models\VarshphalVarshaYearDocument;
use Kaaljyoti\Result;

/**
 * `$kj->varshphal` — one document, served in five sections.
 *
 * All five take the same `VarshphalRequest` — a birth and the year the annual
 * chart is for — and each costs its own credit.
 */
final readonly class VarshphalApi extends ApiSection
{
    /**
     * `POST /v1/varshphal` — the annual chart for a year.
     *
     * @return Result<VarshphalVarshaYearDocument, Meta>
     */
    public function get(VarshphalRequest $request): Result
    {
        return $this->document('postVarshphal', $request->toArray(), VarshphalVarshaYearDocument::fromArray(...));
    }

    /**
     * `POST /v1/varshphal/bala` — harsha bala, panchavargiya and dwadasha.
     *
     * @return Result<VarshphalHarshaBalaDocument, Meta>
     */
    public function bala(VarshphalRequest $request): Result
    {
        return $this->document('postVarshphalBala', $request->toArray(), VarshphalHarshaBalaDocument::fromArray(...));
    }

    /**
     * `POST /v1/varshphal/sahams` — the sahams for the year.
     *
     * @return Result<VarshphalSahamsDocument, Meta>
     */
    public function sahams(VarshphalRequest $request): Result
    {
        return $this->document('postVarshphalSahams', $request->toArray(), VarshphalSahamsDocument::fromArray(...));
    }

    /**
     * `POST /v1/varshphal/yogas` — the tajika yogas.
     *
     * @return Result<VarshphalTajikaDocument, Meta>
     */
    public function yogas(VarshphalRequest $request): Result
    {
        return $this->document('postVarshphalYogas', $request->toArray(), VarshphalTajikaDocument::fromArray(...));
    }

    /**
     * `POST /v1/varshphal/dasha` — mudda and patyayini.
     *
     * @return Result<VarshphalMuddaDocument, Meta>
     */
    public function dasha(VarshphalRequest $request): Result
    {
        return $this->document('postVarshphalDasha', $request->toArray(), VarshphalMuddaDocument::fromArray(...));
    }
}
