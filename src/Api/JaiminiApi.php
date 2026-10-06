<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Models\JaiminiAspectsDocument;
use Kaaljyoti\Models\JaiminiKarakamshaDocument;
use Kaaljyoti\Models\JaiminiKarakasDocument;
use Kaaljyoti\Models\JaiminiPadasDocument;
use Kaaljyoti\Models\KundliRequest;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Result;

/**
 * `$kj->jaimini` — one document, served in four sections.
 *
 * Asking for the second section of the same chart is a cache hit, and still
 * costs its own credit.
 */
final readonly class JaiminiApi extends ApiSection
{
    /**
     * `POST /v1/jaimini/karakas` — the chara karakas.
     *
     * @return Result<JaiminiKarakasDocument, Meta>
     */
    public function karakas(KundliRequest $request): Result
    {
        return $this->document('postJaiminiKarakas', $request->toArray(), JaiminiKarakasDocument::fromArray(...));
    }

    /**
     * `POST /v1/jaimini/arudha-padas` — arudha padas for the twelve houses.
     *
     * @return Result<JaiminiPadasDocument, Meta>
     */
    public function arudhaPadas(KundliRequest $request): Result
    {
        return $this->document('postJaiminiArudhaPadas', $request->toArray(), JaiminiPadasDocument::fromArray(...));
    }

    /**
     * `POST /v1/jaimini/aspects` — rashi drishti.
     *
     * @return Result<JaiminiAspectsDocument, Meta>
     */
    public function aspects(KundliRequest $request): Result
    {
        return $this->document('postJaiminiAspects', $request->toArray(), JaiminiAspectsDocument::fromArray(...));
    }

    /**
     * `POST /v1/jaimini/karakamsha` — karakamsha lagna and what sits on it.
     *
     * @return Result<JaiminiKarakamshaDocument, Meta>
     */
    public function karakamsha(KundliRequest $request): Result
    {
        return $this->document(
            'postJaiminiKarakamsha',
            $request->toArray(),
            JaiminiKarakamshaDocument::fromArray(...),
        );
    }
}
