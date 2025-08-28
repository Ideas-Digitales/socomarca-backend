<?php

namespace App\Services;

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
        $this->baseUrl = 'http://seguimiento.random.cl:3003';
        $this->username = 'demo@random.cl';
        $this->password = 'd3m0r4nd0m3RP';
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
        if(env('APP_ENV') == 'local'){
            $this->baseUrl = env('RANDOM_ERP_URL');
            $token = env('RANDOM_ERP_TOKEN');
        } else {
            $token = $this->getToken();
        }
        $response = Http::withHeaders([ 
            'Authorization' => 'Bearer ' . $token
        ])->$method($this->baseUrl . $endpoint, $params);

        Log::info('Token: ' . $token);


        //If token is expired, get new token and make request again
        if(isset($response->json()['message']) && $response->json()['message'] == 'jwt expired'){
            Cache::forget('random_api_token');
            $token = $this->getToken();
            $response = Http::withHeaders([ 
                'Authorization' => 'Bearer ' . $token
            ])->$method($this->baseUrl . $endpoint, $params);
                
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
        // 1. Login para obtener el token
        $loginResponse = Http::post($this->baseUrl . '/login', [
            'username' => 'demo@random.cl',
            'password' => 'd3m0r4nd0m3RP'
        ]);

        if (!$loginResponse->successful()) {
            Log::error('Error autenticando con Random API');
            return;
        }

        $token = $loginResponse->json('token');

        // 2. Usar el token en la petición GET
        $response = Http::withToken($token)
            ->get($this->baseUrl.'/web32/entidades', [
                //'empresa' => '01',
                //'size' => 100
            ]);

        if ($response->successful()) {
            return $response->json();

        } else {
            Log::error('Error obteniendo entidades de Random API');
        }
    }

    public function getProducts($tipr = '', $kopr_anterior = 0, $kopr = '', $nokopr = '', $search = '', $fmpr = '', $pfpr = '', $hfpr = '')
    {
   
        $data = [
            'kopr_anterior' => $kopr_anterior,
            'kopr' => $kopr,
            'nokopr' => $nokopr,
            'search' => $search,
            'fmpr' => $fmpr,
            'pfpr' => $pfpr,
            'hfpr' => $hfpr,
            'fields' => "KOPR,NOKOPR,KOPRAL,NMARCA"
        ];

        if (!empty($tipr)) {
            $data['tipr'] = $tipr;
        }

        return $this->makeRequest('get', '/productos?' . http_build_query($data));
    }

    public function getCategories()
    {
        return $this->makeRequest('get', '/familias');
    }

    public function getPricesLists(){

        $data = [
            'empresa' => env('RANDOM_ERP_BUSINESS_CODE'),
            'modalidad' => env('RANDOM_ERP_PRICES_MODALITY')
        ];
        $request = $this->makeRequest('get', '/web32/precios/pidelistaprecio?' . http_build_query($data));
        return $request;
    }

    public function getStock($kopr = null, $fields = null, $warehouse = null, $business_code = null, $mode = null){
        $data = [];
        
        if ($kopr !== null) $data['kopr'] = $kopr;
        if ($fields !== null) $data['fields'] = $fields;
        if ($warehouse !== null) $data['warehouse'] = $warehouse;
        if ($business_code !== null) $data['business_code'] = $business_code;
        if ($mode !== null) $data['mode'] = $mode;

        $request = $this->makeRequest('get', '/stock/detalle?' . http_build_query($data));
        return $request;
    }
} 