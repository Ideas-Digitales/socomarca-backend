<?php

namespace App\Services\Random;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

/**
 * Documents related to one Random ERP document, as returned by `GET /documentos/traza/{idmaeedo}`.
 *
 * The trace lists every document linked by its detail lines. A sales note (NVV) that is still
 * waiting for a tax document is the only entry. An invoice (FCV), a receipt (BLV) or any other
 * type means the note is already referenced and must not be deleted.
 */
class DocumentTrace
{
    /**
     * Tax document types that settle the NVV of a credit payment.
     *
     * @var list<string>
     */
    public const SETTLING_TYPES = ['FCV', 'BLV'];

    /**
     * @param  Collection<int, array{idmaeedo: int|null, type: string, maeedo: array<string, mixed>}>  $documents
     */
    private function __construct(private Collection $documents) {}

    public static function fromResponse(Response $response): self
    {
        $data = $response->json('data');

        return self::fromPayload(is_array($data) ? $data : []);
    }

    /**
     * @param  array<int, mixed>  $data  `data` array of the trace response
     */
    public static function fromPayload(array $data): self
    {
        $documents = collect($data)
            ->map(function ($item) {
                if (! is_array($item)) {
                    return null;
                }

                $header = $item['maeedo'] ?? null;

                if (! is_array($header)) {
                    return null;
                }

                $type = $header['TIDO'] ?? null;

                if (! is_string($type) || $type === '') {
                    return null;
                }

                $id = $header['IDMAEEDO'] ?? null;

                return [
                    'idmaeedo' => is_numeric($id) ? (int) $id : null,
                    'type' => $type,
                    'maeedo' => $header,
                ];
            })
            ->filter()
            ->values();

        return new self($documents);
    }

    /**
     * Documents in the trace that are not the source document.
     *
     * The source NVV stays out. Another id, or any type other than NVV, is a reference
     * (invoice, receipt, dispatch guide or anything else).
     *
     * @return Collection<int, array{idmaeedo: int|null, type: string, maeedo: array<string, mixed>}>
     */
    public function associatedWith(int $idmaeedo): Collection
    {
        return $this->documents
            ->filter(function (array $document) use ($idmaeedo) {
                if ($document['type'] !== 'NVV') {
                    return true;
                }

                return $document['idmaeedo'] !== null && $document['idmaeedo'] !== $idmaeedo;
            })
            ->values();
    }

    /**
     * First invoice (FCV) or receipt (BLV) in the trace.
     *
     * That document is the one that settles a credit payment. It needs an id so it can be stored.
     *
     * @return array{idmaeedo: int, type: string, maeedo: array<string, mixed>}|null
     */
    public function firstSettlingDocument(): ?array
    {
        $document = $this->documents->first(
            fn (array $document) => $document['idmaeedo'] !== null
                && in_array($document['type'], self::SETTLING_TYPES, true)
        );

        return $document;
    }
}
