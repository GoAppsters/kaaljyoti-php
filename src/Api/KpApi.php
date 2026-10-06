<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Models\KpDocument;
use Kaaljyoti\Models\KundliRequest;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Result;

/**
 * `$kj->kp` — Krishnamurti Paddhati.
 */
final readonly class KpApi extends ApiSection
{
    /**
     * `POST /v1/kp/chart` — Placidus cusps with their sub-lords.
     *
     * @return Result<KpDocument, Meta>
     */
    public function chart(KundliRequest $request): Result
    {
        return $this->document('postKpChart', $request->toArray(), KpDocument::fromArray(...));
    }
}
