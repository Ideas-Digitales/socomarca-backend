<?php

namespace App\Services;

use App\Exceptions\RandomApiServiceErrorException;
use App\Exceptions\RandomDocumentNotDeletableException;
use App\Services\Random\DocumentTrace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RandomApiService
{
    protected $baseUrl;

    protected $username;

    protected $password;

    protected $ttl;

    public function __construct()
    {
        $this->baseUrl = config('random.url');
        $this->username = config('random.username');
        $this->password = config('random.password');
        $this->ttl = 10;
    }

    protected function getToken()
    {
        return Cache::remember('random_api_token', $this->ttl * 60, function () {
            $response = Http::asForm()->post($this->baseUrl.'/login', [
                'username' => $this->username,
                'password' => $this->password,
                'ttl' => $this->ttl,
            ]);

            if ($response->successful()) {
                return $response->json()['token'];
            }

            $error = $response->json();
            throw new \Exception('Error al obtener el token de autenticación: '.$error['message']);
        });
    }

    protected function makeRequest($method, $endpoint, $params = [])
    {
        // if (config('app.env') == 'local') {
        //     $this->baseUrl = config('random.url');
        //     $token = config('random.token');
        // } else {
        //     $token = $this->getToken();
        // }
        $token = config('random.token');
        $response = Http::withToken($token)->acceptJson()->$method($this->baseUrl.$endpoint, $params);

        // If token is expired, get new token and make request again
        if (isset($response->json()['message']) && $response->json()['message'] == 'jwt expired') {
            Cache::forget('random_api_token');
            $token = $this->getToken();
            $response = Http::withToken($token)->acceptJson()->$method($this->baseUrl.$endpoint, $params);

            return $response->json();
        }

        return $response->json();
    }

    public function getEntidades($empresa, $kofu, $modalidad, $size = 5, $page = 1)
    {
        return $this->makeRequest('get', '/web32/entidades', [
            'empresa' => $empresa,
            'kofu' => $kofu,
            'modalidad' => $modalidad,
            'size' => $size,
            'page' => $page,
        ]);
    }

    /**
     * Get the customer entities (entity + branch records) to sync as users.
     *
     * When `fields` is given, Random only returns the requested columns (plus TIPODOCVEN
     * and KOLTVEN), and IDMAEEN is not guaranteed in the default response.
     */
    public function getEntidadesUsuarios($size = 15, $page = 1)
    {
        return $this->makeRequest('get', '/web32/entidades', [
            'empresa' => config('random.business_code'),
            'modalidad' => config('random.modality'),
            'size' => $size,
            'page' => $page,
            // Important! Include "LVEN" parameter to get the right pricing list
            'fields' => 'IDMAEEN,KOEN,SUEN,TIPOSUC,TIEN,RTEN,NOKOEN,SIEN,EMAIL,EMAILCOMER,FOEN,DIEN,CIEN,CMEN,CPOSTAL,LVEN',
        ]);
    }

    /**
     * Credit line of a customer branch.
     *
     * The granted amount is `CRTO` from `GET /web32/entidades`. It is exposed as `CRSD`
     * so existing clients keep reading the limit from that field.
     *
     * Usage (`CRSDVU`, `CRSDVV`) comes from `GET /gestion/credito/resumen/{koen}/{suen}`.
     * That endpoint's own `CRSD` is ignored: Random multiplies the limit once per due date
     * (confirmed 2026-10-15). A 404 means the branch has a limit but no matching movements,
     * so usage is zero.
     *
     * @return array{KOEN: string, SUEN: string, CRSD: int|float, CRSDVU: int|float, CRSDVV: int|float, CRSDCU: int|float, CRSDCV: int|float}
     */
    public function getCreditLine(string $koen, string $suen): array
    {
        $usage = $this->creditUsage($koen, $suen);

        return [
            'KOEN' => $koen,
            'SUEN' => $suen,
            'CRSD' => $this->grantedCredit($koen, $suen),
            'CRSDVU' => $usage['CRSDVU'],
            'CRSDVV' => $usage['CRSDVV'],
            'CRSDCU' => $usage['CRSDCU'],
            'CRSDCV' => $usage['CRSDCV'],
        ];
    }

    /**
     * @return array{CRSDVU: int|float, CRSDVV: int|float, CRSDCU: int|float, CRSDCV: int|float}
     */
    private function creditUsage(string $koen, string $suen): array
    {
        $response = $this->authorizedGet('/gestion/credito/resumen/'.rawurlencode($koen).'/'.rawurlencode($suen));

        if ($response->status() === 404) {
            return [
                'CRSDVU' => 0,
                'CRSDVV' => 0,
                'CRSDCU' => 0,
                'CRSDCV' => 0,
            ];
        }

        if ($response->failed()) {
            $this->failCredit($koen, $suen, $response);
        }

        $data = $response->json();
        foreach (['CRSDVU', 'CRSDVV'] as $key) {
            if (! is_array($data) || ! array_key_exists($key, $data) || ! is_numeric($data[$key])) {
                $this->failCredit($koen, $suen, $response);
            }
        }

        return [
            'CRSDVU' => $this->numericCredit($data['CRSDVU']),
            'CRSDVV' => $this->numericCredit($data['CRSDVV']),
            'CRSDCU' => isset($data['CRSDCU']) && is_numeric($data['CRSDCU']) ? $this->numericCredit($data['CRSDCU']) : 0,
            'CRSDCV' => isset($data['CRSDCV']) && is_numeric($data['CRSDCV']) ? $this->numericCredit($data['CRSDCV']) : 0,
        ];
    }

    /**
     * Granted credit (`CRTO`) of one branch in the entity master.
     */
    private function grantedCredit(string $koen, string $suen): int|float
    {
        $page = 1;
        $size = 100;

        do {
            $response = $this->authorizedGet('/web32/entidades', [
                'empresa' => config('random.business_code'),
                'modalidad' => config('random.modality'),
                'koen' => $koen,
                'fields' => 'IDMAEEN,KOEN,SUEN,CRTO,CRSD',
                'size' => $size,
                'page' => $page,
            ]);

            if ($response->failed()) {
                $this->failCredit($koen, $suen, $response);
            }

            $entities = $response->json();
            if (! is_array($entities) || ! array_is_list($entities)) {
                $this->failCredit($koen, $suen, $response);
            }

            foreach ($entities as $entity) {
                if (! is_array($entity) || trim((string) ($entity['SUEN'] ?? '')) !== trim($suen)) {
                    continue;
                }

                if (! isset($entity['CRTO']) || ! is_numeric($entity['CRTO'])) {
                    $this->failCredit($koen, $suen, $response);
                }

                return $this->numericCredit($entity['CRTO']);
            }

            $count = count($entities);
            $page++;
        } while ($count === $size && $page <= 20);

        $this->failCredit($koen, $suen, new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(404, ['Content-Type' => 'application/json'], '{"message":"No se encuentra el recurso"}')
        ));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function authorizedGet(string $path, array $query = []): \Illuminate\Http\Client\Response
    {
        $token = ! empty(config('random.token')) ? config('random.token') : $this->getToken();

        try {
            return Http::withToken($token)
                ->retry(2, 1000, null, false)
                ->acceptJson()
                ->get($this->baseUrl.$path, $query);
        } catch (\Throwable $e) {
            Log::error('Random API Error', [
                'message' => $e->getMessage(),
            ]);

            throw new RandomApiServiceErrorException(
                'No se pudo obtener el crédito del cliente',
                ['path' => $path],
                null
            );
        }
    }

    private function failCredit(string $koen, string $suen, ?\Illuminate\Http\Client\Response $response): never
    {
        throw new RandomApiServiceErrorException(
            'No se pudo obtener el crédito del cliente',
            [
                'koen' => $koen,
                'suen' => $suen,
            ],
            $response
        );
    }

    private function numericCredit(int|float|string $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    public function getProducts($tipr = '', $kopr_anterior = 0, $kopr = '', $nokopr = '', $search = '', $fmpr = '', $pfpr = '', $hfpr = '')
    {
        $params = [
            'empresa' => config('random.business_code'),
            'fields' => 'KOPR,NOKOPR,KOPRAL,NMARCA,FMPR,PFPR,MRPR,HFPR',
        ];

        // Only add non-empty parameters
        if (! empty($tipr)) {
            $params['tipr'] = $tipr;
        }
        if ($kopr_anterior > 0) {
            $params['kopr_anterior'] = $kopr_anterior;
        }
        if (! empty($kopr)) {
            $params['kopr'] = $kopr;
        }
        if (! empty($nokopr)) {
            $params['nokopr'] = $nokopr;
        }
        if (! empty($search)) {
            $params['search'] = $search;
        }
        if (! empty($fmpr)) {
            $params['fmpr'] = $fmpr;
        }
        if (! empty($pfpr)) {
            $params['pfpr'] = $pfpr;
        }
        if (! empty($hfpr)) {
            $params['hfpr'] = $hfpr;
        }

        return $this->makeRequest('get', '/productos?tipr=FPN', $params);
    }

    public function getCategories()
    {
        return $this->makeRequest('get', '/familias');
    }

    public function getPricesLists($kolt = null, $size = 100, $page = 1)
    {
        $params = [
            'empresa' => config('random.business_code'),
            'kofu' => config('random.modality'),
            'size' => $size,
            'page' => $page,
        ];

        if ($kolt !== null) {
            $params['kolt'] = $kolt;
        }

        return $this->makeRequest('get', '/web32/precios/pidelistaprecio', $params);
    }

    public function getStock($kopr = null, $fields = null, $warehouse = null, $business_code = null, $mode = null)
    {
        $params = [
            'modalidad' => config('random.modality'),
            'kobo' => config('random.warehouse_code'),
        ];

        if ($kopr !== null) {
            $params['kopr'] = $kopr;
        }
        if ($fields !== null) {
            $params['fields'] = $fields;
        }
        if ($warehouse !== null) {
            $params['warehouse'] = $warehouse;
        }
        if ($business_code !== null) {
            $params['business_code'] = $business_code;
        }
        if ($mode !== null) {
            $params['mode'] = $mode;
        }

        return $this->makeRequest('get', '/stock/detalle', $params);
    }

    public function getBrands()
    {
        $params = [
            'empresa' => config('random.business_code'),
            'fields' => 'KOPR,MRPR,NOKOMR',
        ];

        return $this->makeRequest('get', '/productos', $params);
    }

    /**
     * Create a document
     */
    public function createDocument(array $data): \Illuminate\Http\Client\Response
    {
        $endpoint = '/web32/documento';

        if (config('app.env') == 'local') {
            $token = config('random.token');
        } else {
            $token = $this->getToken();
        }

        Log::debug('Random URL (RandomApiService): '.$this->baseUrl);

        try {
            $dryRun = config('random.documents.dry_run');
            $response = Http::withToken($token)
                ->when($dryRun, function ($static, $whenParameter) {
                    $static->withQueryParameters([
                        'dryRun' => 'true',
                    ]);
                })
                ->retry(2, 1000, null, false)
                ->acceptJson()
                ->post($this->baseUrl.$endpoint, $data);
        } catch (\Throwable $e) {
            Log::error('Random API Error', [
                'message' => $e->getMessage(),
            ]);
            $response = null;
        }

        if ($response === null || $response->failed()) {
            $exception = new RandomApiServiceErrorException(
                'Falló la creación del documento en Random ERP',
                $data,
                $response
            );

            throw $exception;
        }

        return $response;
    }

    /**
     * Get document trace
     */
    public function getDocumentTrace(int $idmaeedo): \Illuminate\Http\Client\Response
    {
        $endpoint = "/documentos/traza/{$idmaeedo}";

        if (! empty(config('random.token'))) {
            $token = config('random.token');
        } else {
            $token = $this->getToken();
        }

        $response = Http::withToken($token)
            ->retry(2, 1000, null, false)
            ->acceptJson()
            ->get($this->baseUrl.$endpoint);

        if ($response->failed()) {
            $exception = new RandomApiServiceErrorException(
                'Document trace query failed',
                ['idmaeedo' => $idmaeedo],
                $response
            );

            throw $exception;
        }

        return $response;
    }

    /**
     * Delete a document in Random ERP.
     *
     * `$idmaeedo` is the document id stored in `random_documents.idmaeedo`.
     *
     * Random only allows the deletion once every reference is gone (another document, a payment,
     * accounting or an electronic tax document). The trace is read first, with the same rule
     * `ProcessPendingCreditPaymentJob` uses to detect an invoice or a receipt. If it lists any
     * document other than this one, the DELETE is not sent.
     *
     * @see DELETE /web32/documento/{idmaeedo}
     *
     * @throws RandomDocumentNotDeletableException
     * @throws RandomApiServiceErrorException
     */
    public function deleteDocument(int $idmaeedo): \Illuminate\Http\Client\Response
    {
        $associated = DocumentTrace::fromResponse($this->getDocumentTrace($idmaeedo))
            ->associatedWith($idmaeedo);

        if ($associated->isNotEmpty()) {
            throw new RandomDocumentNotDeletableException($idmaeedo, $associated->all());
        }

        $endpoint = "/web32/documento/{$idmaeedo}";

        if (! empty(config('random.token'))) {
            $token = config('random.token');
        } else {
            $token = $this->getToken();
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->delete($this->baseUrl.$endpoint);

        if ($response->failed()) {
            throw new RandomApiServiceErrorException(
                'Random ERP document deletion failed',
                ['idmaeedo' => $idmaeedo],
                $response
            );
        }

        return $response;
    }

    /**
     * Get Random ERP Territorios
     *
     * @param  int  $level
     * @param  string  $countryCode
     */
    public function getTerritorios($level = 3, $countryCode = 'CL'): \Illuminate\Http\Client\Response
    {
        $endpoint = '/territorios';
        $query = [
            'ter1' => $countryCode,
            'nivel' => strval($level),
        ];

        if (! empty(config('random.token'))) {
            $token = config('random.token');
        } else {
            $token = $this->getToken();
        }

        $response = Http::withToken($token)
            ->retry(2, 1000, null, false)
            ->acceptJson()
            ->get($this->baseUrl.$endpoint, $query);

        if ($response->failed()) {
            $exception = new RandomApiServiceErrorException(
                'Territorios query failed',
                ['query' => $query],
                $response
            );

            throw $exception;
        }

        return $response;
    }

    /**
     * Contacts of an entity (`GET /web32/contactos`).
     *
     * @return array<string, mixed>
     */
    public function getContacts(string $codigoEntidad): array
    {
        $response = $this->contactsRequest('get', [
            'codigoEntidad' => $codigoEntidad,
        ]);

        if ($response->failed()) {
            throw new RandomApiServiceErrorException(
                'No se pudieron obtener los contactos de la entidad',
                ['codigoEntidad' => $codigoEntidad],
                $response
            );
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Create or update contacts (`PUT /web32/contactos`).
     *
     * The body is a JSON array. Random answers with how many rows it created and updated.
     * The call is synchronous: checkout can use the contact as soon as this returns.
     *
     * @param  list<array<string, mixed>>  $contacts
     * @return array{updated: int, created: int}
     */
    public function updateContacts(array $contacts): array
    {
        $response = $this->contactsRequest('put', $contacts);
        $data = $response->json();
        $updated = is_array($data) ? ($data['updated'] ?? null) : null;
        $created = is_array($data) ? ($data['created'] ?? null) : null;

        if (
            $response->failed()
            || ! is_numeric($updated)
            || ! is_numeric($created)
            || ((int) $updated + (int) $created) < 1
        ) {
            throw new RandomApiServiceErrorException(
                'No se pudo actualizar el contacto en Random ERP',
                ['contacts' => $contacts],
                $response
            );
        }

        return [
            'updated' => (int) $updated,
            'created' => (int) $created,
        ];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $payload
     */
    private function contactsRequest(string $method, array $payload): \Illuminate\Http\Client\Response
    {
        $token = ! empty(config('random.token')) ? config('random.token') : $this->getToken();

        try {
            $pending = Http::withToken($token)
                ->retry(2, 1000, null, false)
                ->acceptJson()
                ->asJson();

            $url = $this->baseUrl.'/web32/contactos';

            return $method === 'get'
                ? $pending->get($url, $payload)
                : $pending->put($url, $payload);
        } catch (\Throwable $e) {
            Log::error('Random API Error', [
                'message' => $e->getMessage(),
            ]);

            throw new RandomApiServiceErrorException(
                'No se pudo comunicar con Random ERP',
                ['endpoint' => '/web32/contactos'],
                null
            );
        }
    }
}
