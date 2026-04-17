<?php

namespace App\Services;

use App\Exceptions\RandomApiServiceErrorException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
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
            $response = Http::asForm()->post($this->baseUrl . '/login', [
                'username' => $this->username,
                'password' => $this->password,
                'ttl' => $this->ttl
            ]);

            if ($response->successful()) {
                return $response->json()['token'];
            }

            $error = $response->json();
            throw new \Exception('Error al obtener el token de autenticación: ' . $error['message']);
        });
    }

    protected function makeRequest($method, $endpoint, $params = [])
    {
        if (config('app.env') == 'local') {
            $this->baseUrl = config('random.url');
            $token = config('random.token');
        } else {
            $token = $this->getToken();
        }
        $response = Http::withToken($token)->acceptJson()->$method($this->baseUrl . $endpoint, $params);

        //If token is expired, get new token and make request again
        if (isset($response->json()['message']) && $response->json()['message'] == 'jwt expired') {
            Cache::forget('random_api_token');
            $token = $this->getToken();
            $response = Http::withToken($token)->acceptJson()->$method($this->baseUrl . $endpoint, $params);

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
            'page' => $page
        ]);
    }

    public function getEntidadesUsuarios($size = 15, $page = 1)
    {
        return $this->makeRequest('get', '/web32/entidades', [
            'size' => $size,
            'page' => $page
        ]);
    }

    public function fetchAndUpdateUsers()
    {
        return $this->makeRequest('get', '/web32/entidades');
    }

    public function getCreditLine(string $koen, string $suen): \Illuminate\Http\Client\Response
    {
        $endpoint = "{$this->baseUrl}/gestion/credito/resumen/{$koen}/{$suen}";

        if (config('app.env') == 'local') {
            $token = config('random.token');
        } else {
            $token = $this->getToken();
        }

        $response = Http::withToken($token)
            ->retry(2, 1000, null, false)
            ->acceptJson()
            ->get($endpoint);

        if ($response->failed()) {
            $exception = new RandomApiServiceErrorException(
                "Random API Error",
                $response->status(),
                [
                    "response_fragment" => $response->collect()->take(10)
                ]
            );

            throw $exception;
        }


        $requiredKeys = [
            'KOEN',
            'SUEN',
            'CRSD',
            'CRSDVU',
            'CRSDVV',
            'CRSDCU',
            'CRSDCV'
        ];

        $data = $response->json();

        $isValid = is_array($data);

        if ($isValid) {
            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $data)) {
                    $isValid = false;
                    break;
                }
            }
        }

        if (!$isValid) {
            $exception = new RandomApiServiceErrorException(
                "Random API Error",
                $response->status(),
                [
                    "response_fragment" => $response->collect()->take(10)
                ]
            );

            throw $exception;
        }

        return $response;
    }

    public function getProducts($tipr = '', $kopr_anterior = 0, $kopr = '', $nokopr = '', $search = '', $fmpr = '', $pfpr = '', $hfpr = '')
    {
        $params = [
            'empresa' => config('random.business_code'),
            'fields' => "KOPR,NOKOPR,KOPRAL,NMARCA,FMPR,PFPR,MRPR"
        ];

        // Only add non-empty parameters
        if (!empty($tipr)) $params['tipr'] = $tipr;
        if ($kopr_anterior > 0) $params['kopr_anterior'] = $kopr_anterior;
        if (!empty($kopr)) $params['kopr'] = $kopr;
        if (!empty($nokopr)) $params['nokopr'] = $nokopr;
        if (!empty($search)) $params['search'] = $search;
        if (!empty($fmpr)) $params['fmpr'] = $fmpr;
        if (!empty($pfpr)) $params['pfpr'] = $pfpr;
        if (!empty($hfpr)) $params['hfpr'] = $hfpr;

        return $this->makeRequest('get', '/productos', $params);
    }

    public function getCategories()
    {
        return $this->makeRequest('get', '/familias');
    }

    public function getPricesLists()
    {
        $params = [
            'empresa' => config('random.business_code'),
            'modalidad' => config('random.modality')
        ];
        return $this->makeRequest('get', '/web32/precios/pidelistaprecio', $params);
    }

    public function getStock($kopr = null, $fields = null, $warehouse = null, $business_code = null, $mode = null)
    {
        $params = [];

        if ($kopr !== null) $params['kopr'] = $kopr;
        if ($fields !== null) $params['fields'] = $fields;
        if ($warehouse !== null) $params['warehouse'] = $warehouse;
        if ($business_code !== null) $params['business_code'] = $business_code;
        if ($mode !== null) $params['mode'] = $mode;

        return $this->makeRequest('get', '/stock/detalle', $params);
    }

    public function getBrands()
    {
        $params = [
            'empresa' => config('random.business_code'),
            'fields' => 'KOPR,MRPR,NOKOMR'
        ];

        return $this->makeRequest('get', '/productos', $params);
    }

    public function createFcvDocument(array $data, bool $dryRun = false)
    {
        $endpoint = '/web32/documento';

        if (config('app.env') == 'local') {
            $token = config('random.token');
        } else {
            $token = $this->getToken();
        }

        $response = Http::withToken($token)
            ->retry(2, 1000)
            ->withQueryParameters(['dryRun' => true]) // TODO remove this when ready to create real documents
            ->acceptJson()
            ->post($this->baseUrl . $endpoint, $data);

        return $this->makeRequest('post', $endpoint, $data);
    }
}
