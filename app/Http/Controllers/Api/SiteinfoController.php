<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteInfo\SiteinfoResource;
use App\Models\Siteinfo;
use Illuminate\Http\Request;

class SiteinfoController extends Controller
{
    /**
     * Get the site information.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show()
    {
        
        $header = Siteinfo::where('key', 'header')->first();
        $footer = Siteinfo::where('key', 'footer')->first();
        $social = Siteinfo::where('key', 'social_media')->first();
        $customerMessageEnabled = Siteinfo::where('key', 'customer_message_enabled')->first();

        return response()->json([
            'header' => $header ? $header->value : (object)[
                'contact_phone' => '',
                'contact_email' => ''
            ],
            'footer' => $footer ? $footer->value : (object)[
                'contact_phone' => '',
                'contact_email' => ''
            ],
            'social_media' => $social ? $social->value : [
                [
                    'label' => '',
                    'link' => ''
                ]
            ],
            'customer_message_enabled' => $customerMessageEnabled ? (bool)$customerMessageEnabled->value : false,
        ]);
    }   
    
    
    /**
     * Update the site information.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'header' => 'required|array',
            'footer' => 'required|array',
            'social_media' => 'required|array',
        ]);

        // Guarda o actualiza cada sección
        Siteinfo::updateOrCreate(
            ['key' => 'header'],
            ['value' => $data['header']]
        );
        Siteinfo::updateOrCreate(
            ['key' => 'footer'],
            ['value' => $data['footer']]
        );
        Siteinfo::updateOrCreate(
            ['key' => 'social_media'],
            ['value' => $data['social_media']]
        );

        return response()->json(['message' => 'Siteinfo updated successfully']);
    }

    /**
     * Get the terms of service.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function terms()
    {
        $terms = Siteinfo::where('key', 'terms')->first();
        return response()->json([
            'content' => $terms ? $terms->content : '',
        ]);
        
    }

    /**
     * Update the terms of service.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateTerms(Request $request)
    {
        $data = $request->validate([
            'content' => 'required|string',
        ]);

        Siteinfo::updateOrCreate(
            ['key' => 'terms'],
            ['content' => $data['content']]
        );

        return response()->json(['message' => 'Terms upadated succesfully']);
    }

    /**
     * Get the privacy policy.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function privacyPolicy()
    {
        $privacy = Siteinfo::where('key', 'privacy_policy')->first();

        return response()->json([
            'content' => $privacy ? $privacy->content : '',
        ]);
    }

    /**
     * Update the privacy policy.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePrivacyPolicy(Request $request)
    {
        $data = $request->validate([
            'content' => 'required|string',
        ]);

        Siteinfo::updateOrCreate(
            ['key' => 'privacy_policy'],
            ['content' => $data['content']]
        );

        return response()->json(['message' => 'Privacy Policy updated successfully']);
    }

    /**
     * Get the customer message.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function customerMessage()
    {
        $msg = Siteinfo::where('key', 'customer_message')->first();

        return response()->json($msg && $msg->value ? $msg->value : [
            "header" => [
                "color" => "#ffffff",
                "content" => ""
            ],
            "banner" => [
                "desktop_image" => "",
                "mobile_image" => "",
                "enabled" => false
            ],
            "modal" => [
                "image" => "",
                "enabled" => false
            ]
        ]);
    }

    /**
     * Update the customer message.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCustomerMessage(Request $request)
    {
        $data = $request->validate([
            'header_color' => 'required|string',
            'header_content' => 'nullable|string',
            'banner_desktop_image' => 'nullable|image',
            'banner_mobile_image' => 'nullable|image',
            'banner_enabled' => 'required|boolean',
            'modal_image' => 'nullable|image',
            'modal_enabled' => 'required|boolean',
            'message_enabled' => 'required|boolean',
        ]);

        $customerMessage = Siteinfo::where('key', 'customer_message')->first();
        $oldValue = $customerMessage ? $customerMessage->value : [];

        $bannerDesktopImage = $request->file('banner_desktop_image')
            ? asset('storage/' . $request->file('banner_desktop_image')->store('customer-message', 'public'))
            : ($oldValue['banner']['desktop_image'] ?? '');

        $bannerMobileImage = $request->file('banner_mobile_image')
            ? asset('storage/' . $request->file('banner_mobile_image')->store('customer-message', 'public'))
            : ($oldValue['banner']['mobile_image'] ?? '');

        $modalImage = $request->file('modal_image')
            ? asset('storage/' . $request->file('modal_image')->store('customer-message', 'public'))
            : ($oldValue['modal']['image'] ?? '');

        $value = [
            'header' => [
                'color' => $data['header_color'],
                'content' => $data['header_content'] ?? '',
            ],
            'banner' => [
                'desktop_image' => $bannerDesktopImage,
                'mobile_image' => $bannerMobileImage,
                'enabled' => (bool)$data['banner_enabled'],
            ],
            'modal' => [
                'image' => $modalImage,
                'enabled' => (bool)$data['modal_enabled'],
            ],
            'message' => [
                
                'enabled' => (bool)$data['message_enabled'],
            ],
        ];

        Siteinfo::updateOrCreate(
            ['key' => 'customer_message'],
            ['value' => $value]
        );

        Siteinfo::updateOrCreate(
            ['key' => 'customer_message_enabled'],
            ['value' => $data['message_enabled']]
        );

        return response()->json(['message' => 'Mensaje de bienvenida actualizado correctamente.']);
    }

    /**
     * Get the webpay configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function webpayConfig()
    {
        $record = Siteinfo::where('key', 'WEBPAY_INFO')->first();
        $data = $record ? $record->value : [];

        if(!$data){
            return response()->json([
                'message' => 'No se encontró la configuración de Webpay',
                'data' => []
            ],404);
        }

        return response()->json($data);
    }

    /**
     * Update the webpay configuration.
     * Solo puede ser actualizada por el superadmin.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateWebpayConfig(Request $request)
    {
        $data = $request->validate([
            'WEBPAY_COMMERCE_CODE' => 'required|string',
            'WEBPAY_API_KEY' => 'required|string',
            'WEBPAY_ENVIRONMENT' => 'required|string|in:integration,production',
            'WEBPAY_RETURN_URL' => 'required|url',
        ]);

        Siteinfo::updateOrCreate(
            ['key' => 'WEBPAY_INFO'],
            [
                'value' => $data,
                'content' => 'Informacion de entorno webpay',
            ]
        );
 
        return response()->json(
            [
                'message' => 'Configuración de Webpay actualizada exitosamente',
                'data' => $data
            ]
        );
    }

    /**
     * Get upload settings
    */
    public function getUploadSettings(Request $request)
    {
        $uploadSettings = Siteinfo::where('key', 'upload_settings')->first();

        if (!$uploadSettings) {
            return response()->json([
                'data' => [
                    'max_upload_size' => 50, // Valor por defecto
                    'content' => 'Configuración de tamaño máximo para subida de archivos'
                ]
            ]);
        }

        return response()->json([
            'data' => [
                'max_upload_size' => $uploadSettings->value['max_upload_size'] ?? 50,
                'content' => $uploadSettings->content
            ]
        ]);
    }

    /**
     * Update upload settings
     */
    public function updateUploadSettings(Request $request)
    {
        $data = $request->validate([
            'max_upload_size' => 'required|integer|min:1|max:1024', // Máximo 1GB
        ]);

        $uploadSettings = Siteinfo::where('key', 'upload_settings')->first();
        $oldValue = $uploadSettings ? $uploadSettings->value : [];

        $value = [
            'max_upload_size' => $data['max_upload_size'],
        ];

        Siteinfo::updateOrCreate(
            ['key' => 'upload_settings'],
            [
                'value' => $value,
                'content' => 'Configuración de tamaño máximo para subida de archivos'
            ]
        );

        return response()->json([
            'message' => 'Configuración de subida actualizada correctamente.',
            'data' => $value
        ]);
    }

    /**
     * Get cart reservation timeout configuration
     */
    public function getCartReservationTimeout()
    {
        $config = Siteinfo::where('key', 'CART_RESERVATION_TIMEOUT')->first();

        if (!$config) {
            return response()->json([
                'data' => [
                    'timeout_minutes' => 1440, // Default: 24 hours
                    'content' => 'Tiempo de expiración de reservas del carrito en minutos'
                ]
            ]);
        }

        return response()->json([
            'data' => [
                'timeout_minutes' => $config->value['timeout_minutes'] ?? 1440,
                'content' => $config->content
            ]
        ]);
    }

    /**
     * Update cart reservation timeout configuration
     * Requires update-system-config permission
     */
    public function updateCartReservationTimeout(Request $request)
    {
        $data = $request->validate([
            'timeout_minutes' => 'required|integer|min:1|max:10080', // Min 1 minute, Max 7 days
        ]);

        $value = [
            'timeout_minutes' => $data['timeout_minutes'],
        ];

        Siteinfo::updateOrCreate(
            ['key' => 'CART_RESERVATION_TIMEOUT'],
            [
                'value' => $value,
                'content' => 'Tiempo de expiración de reservas del carrito en minutos'
            ]
        );

        return response()->json([
            'message' => 'Configuración de timeout de reservas actualizada correctamente.',
            'data' => $value
        ]);
    }
}
