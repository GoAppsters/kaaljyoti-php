<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Generated\Json;
use Kaaljyoti\Generated\Operations;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Result;
use Kaaljyoti\Transport;

/**
 * One namespace of the client: a transport and the shape forty-seven of the
 * fifty-seven operations share.
 *
 * **No path string is typed twice.** Every method looks its path up in the
 * generated operation table by `operationId`, so a renamed endpoint is an
 * `InvalidArgumentException` naming the id that is gone rather than a 404 in
 * production, and the contract test walks the same table against
 * `openapi/openapi.json`.
 */
abstract readonly class ApiSection
{
    /** Binds the namespace to the client's transport. */
    public function __construct(protected Transport $transport)
    {
    }

    /**
     * A POST that answers a document and the standard `meta`.
     *
     * @template TDocument of object
     * @param string $operationId The `operationId` in the generated table, e.g. `postKundliDasha`.
     * @param array<string, mixed> $body The request, as the generated `toArray()` wrote it.
     * @param callable(array<string, mixed>): TDocument $fromArray The document's own reader.
     * @return Result<TDocument, Meta>
     * @throws \Kaaljyoti\KaaljyotiException for every failure the API or the socket produces.
     */
    protected function document(string $operationId, array $body, callable $fromArray): Result
    {
        return $this->transport->post(
            Operations::byId($operationId)->path,
            $body,
            // Neither closure declares a return type on purpose: writing
            // `mixed` would throw away the document type the caller is
            // promised, and the inferred type is exactly `TDocument`.
            static fn (mixed $json) => $fromArray(Json::object($json)),
            static fn (mixed $json) => Meta::fromArray(Json::object($json)),
        );
    }
}
