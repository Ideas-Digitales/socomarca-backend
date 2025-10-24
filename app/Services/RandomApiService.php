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
        $response = Http::withToken($token)->acceptJson()->$method($this->baseUrl . $endpoint, $params);

        //If token is expired, get new token and make request again
        if(isset($response->json()['message']) && $response->json()['message'] == 'jwt expired'){
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

    public function getProducts($tipr = '', $kopr_anterior = 0, $kopr = '', $nokopr = '', $search = '', $fmpr = '', $pfpr = '', $hfpr = '')
    {
        $params = [
            'empresa' => env('RANDOM_ERP_BUSINESS_CODE'),
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
            'empresa' => env('RANDOM_ERP_BUSINESS_CODE'),
            'modalidad' => env('RANDOM_ERP_PRICES_MODALITY')
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

    public function getBrands(){
        $params = [
            'empresa' => env('RANDOM_ERP_BUSINESS_CODE'),
            'fields' => 'KOPR,MRPR,NOKOMR'
        ];
        
        return $this->makeRequest('get', '/productos', $params);
    }

    public function getWarehouses()
    {
        return $this->makeRequest('get', '/bodegas');
    }
    
} 