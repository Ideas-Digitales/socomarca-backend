<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteInfo\SiteinfoResource;
use App\Models\Siteinfo;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

#[Group('Site content', 'Content shown in the store: contact details, social media links, terms of service, privacy policy and the customer message.', weight: 19)]
class SiteinfoController extends Controller
{
    /**
     * Get the site information
     *
     * Returns the contact details of the header and footer, the social media links and whether the
     * customer message is enabled. Sections never saved come back with empty values.
     */
    public function show()
    {
        
        $header = Siteinfo::where('key', 'header')->first();
        $footer = Siteinfo::where('key', 'footer')->first();
        $social = Siteinfo::where('key', 'social_media')->first();
        $customerMessageEnabled = Siteinfo::where('key', 'customer_message_enabled')->first();

        return response()->json([
            /** @var array{contact_phone: string, contact_email: string} */
            'header' => $header ? $header->value : (object)[
                'contact_phone' => '',
                'contact_email' => ''
            ],
            /** @var array{contact_phone: string, contact_email: string} */
            'footer' => $footer ? $footer->value : (object)[
                'contact_phone' => '',
                'contact_email' => ''
            ],
            /** @var list<array{label: string, link: string}> */
            'social_media' => $social ? $social->value : [
                [
                    'label' => '',
                    'link' => ''
                ]
            ],
            /** Whether the customer message is shown, as set with `PUT /customer-message`. */
            'customer_message_enabled' => $customerMessageEnabled ? (bool)$customerMessageEnabled->value : false,
        ]);
    }   
    
    
    /**
     * Update the site information
     *
     * Replaces the header, footer and social media sections with the ones sent.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            /**
             * Contact details shown in the header.
             *
             * @var array{contact_phone: string, contact_email: string}
             */
            'header' => 'required|array',
            /**
             * Contact details shown in the footer.
             *
             * @var array{contact_phone: string, contact_email: string}
             */
            'footer' => 'required|array',
            /** @var list<array{label: string, link: string}> */
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
     * Get the terms of service
     *
     * Returns an empty `content` until the terms are saved.
     */
    public function terms()
    {
        $terms = Siteinfo::where('key', 'terms')->first();
        return response()->json([
            'content' => $terms ? $terms->content : '',
        ]);
        
    }

    /**
     * Update the terms of service
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
     * Get the privacy policy
     *
     * Returns an empty `content` until the privacy policy is saved.
     */
    public function privacyPolicy()
    {
        $privacy = Siteinfo::where('key', 'privacy_policy')->first();

        return response()->json([
            'content' => $privacy ? $privacy->content : '',
        ]);
    }

    /**
     * Update the privacy policy
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
     * Get the customer message
     *
     * Returns the welcome content shown to customers: a header bar, a banner of slides and a modal.
     * Images are public URLs. Until it is saved, everything comes back disabled and empty, without
     * `message`.
     *
     * @response array{header: array{color: string, content: string}, banner: array{enabled: bool, slides: list<array{id: string, desktop_image: string, mobile_image: string, alt: string, order: int, enabled: bool}>}, modal: array{image: string, enabled: bool}, message?: array{enabled: bool}}
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
                "enabled" => false,
                "slides" => []
            ],
            "modal" => [
                "image" => "",
                "enabled" => false
            ]
        ]);
    }

    /**
     * Update the customer message
     *
     * Replaces the whole customer message; send it as `multipart/form-data`. Images are uploaded to S3,
     * and an image left out keeps the current one.
     *
     * Send `banner_slides` to set every slide of the banner: slides left out are removed, and the
     * images of a slide are kept only through `existing_desktop_image` and `existing_mobile_image`.
     * Without `banner_slides`, `banner_desktop_image` and `banner_mobile_image` set a single slide.
     */
    public function updateCustomerMessage(Request $request)
    {
        $data = $request->validate([
            /**
             * Background color of the header bar.
             *
             * @example #1d4ed8
             */
            'header_color' => 'required|string',
            /** Text of the header bar. */
            'header_content' => 'nullable|string',
            /** Desktop image of a single-slide banner. Ignored when `banner_slides` is sent. */
            'banner_desktop_image' => 'nullable|image',
            /** Mobile image of a single-slide banner. Ignored when `banner_slides` is sent. */
            'banner_mobile_image' => 'nullable|image',
            'banner_enabled' => 'required|boolean',
            /** Slides of the banner, sorted by `order`. Slides without any image are dropped. */
            'banner_slides' => 'nullable|array',
            /** ID of an existing slide; new slides get a generated one. */
            'banner_slides.*.id' => 'nullable|string',
            'banner_slides.*.desktop_image' => 'nullable|image',
            'banner_slides.*.mobile_image' => 'nullable|image',
            /** URL of the current desktop image, to keep it when no new `desktop_image` is sent. */
            'banner_slides.*.existing_desktop_image' => 'nullable|string',
            /** URL of the current mobile image, to keep it when no new `mobile_image` is sent. */
            'banner_slides.*.existing_mobile_image' => 'nullable|string',
            /** @default Banner principal */
            'banner_slides.*.alt' => 'nullable|string',
            /** Position of the slide. Defaults to its position in `banner_slides`, starting at 1. */
            'banner_slides.*.order' => 'nullable|integer',
            /** @default true */
            'banner_slides.*.enabled' => 'nullable|boolean',
            'modal_image' => 'nullable|image',
            'modal_enabled' => 'required|boolean',
            /** Whether the customer message is shown at all. */
            'message_enabled' => 'required|boolean',
        ]);

        $customerMessage = Siteinfo::where('key', 'customer_message')->first();
        $oldValue = $customerMessage ? $customerMessage->value : [];

        $bannerSlides = $this->buildBannerSlides($request, $oldValue['banner']['slides'] ?? []);

        $modalImage = $this->saveImage($request, 'modal_image', 'customer-message/modal')
            ?? ($oldValue['modal']['image'] ?? '');

        $value = [
            'header' => [
                'color' => $data['header_color'],
                'content' => $data['header_content'] ?? '',
            ],
            'banner' => [
                'enabled' => (bool)$data['banner_enabled'],
                'slides' => $bannerSlides,
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

    private function buildBannerSlides(Request $request, array $oldSlides): array
    {
        if (!$request->has('banner_slides')) {
            $desktopImage = $this->saveImage($request, 'banner_desktop_image', 'customer-message/banner-desktop')
                ?? ($oldSlides[0]['desktop_image'] ?? '');

            $mobileImage = $this->saveImage($request, 'banner_mobile_image', 'customer-message/banner-mobile')
                ?? ($oldSlides[0]['mobile_image'] ?? '');

            if (!$desktopImage && !$mobileImage) {
                return [];
            }

            return [[
                'id' => $oldSlides[0]['id'] ?? uniqid('banner_', true),
                'desktop_image' => $desktopImage,
                'mobile_image' => $mobileImage,
                'alt' => $oldSlides[0]['alt'] ?? 'Banner principal',
                'order' => 1,
                'enabled' => true,
            ]];
        }

        $slides = [];

        foreach ($request->input('banner_slides', []) as $index => $slide) {
            $desktopImage = $this->saveImage($request, "banner_slides.$index.desktop_image", 'customer-message/banner-desktop')
                ?? ($slide['existing_desktop_image'] ?? '');

            $mobileImage = $this->saveImage($request, "banner_slides.$index.mobile_image", 'customer-message/banner-mobile')
                ?? ($slide['existing_mobile_image'] ?? '');

            if (!$desktopImage && !$mobileImage) {
                continue;
            }

            $slides[] = [
                'id' => $slide['id'] ?? uniqid('banner_', true),
                'desktop_image' => $desktopImage,
                'mobile_image' => $mobileImage,
                'alt' => $slide['alt'] ?? 'Banner principal',
                'order' => (int)($slide['order'] ?? $index + 1),
                'enabled' => array_key_exists('enabled', $slide) ? (bool)$slide['enabled'] : true,
            ];
        }

        return collect($slides)->sortBy('order')->values()->all();
    }

    private function saveImage(Request $request, string $requestFileName, string $s3path)
    {
        $file = $request->file($requestFileName);
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            $s3path = rtrim($s3path, '/');
            $newFileName = uniqid() . '.' . $file->getClientOriginalExtension();
            $fullPath = $s3path . '/' . $newFileName;
            Storage::disk('s3')->put($fullPath, file_get_contents($file->getRealPath()));
            return Storage::disk('s3')->url($fullPath);
        }
        return null;
    }

    /**
     * Get the Webpay configuration
     *
     * Returns the Transbank Webpay Plus credentials used to take payments.
     */
    #[Group('Settings', weight: 20)]
    #[Response(404, 'The Webpay configuration has not been saved')]
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

        /** @var array{WEBPAY_COMMERCE_CODE: string, WEBPAY_API_KEY: string, WEBPAY_ENVIRONMENT: 'integration'|'production', WEBPAY_RETURN_URL: string} */
        return response()->json($data);
    }

    /**
     * Update the Webpay configuration
     *
     * Replaces the Transbank Webpay Plus credentials used to take payments.
     */
    #[Group('Settings', weight: 20)]
    public function updateWebpayConfig(Request $request)
    {
        $data = $request->validate([
            /** @example 597055555532 */
            'WEBPAY_COMMERCE_CODE' => 'required|string',
            'WEBPAY_API_KEY' => 'required|string',
            /** `integration` for Transbank's test environment, `production` for real payments. */
            'WEBPAY_ENVIRONMENT' => 'required|string|in:integration,production',
            /** Frontend URL Webpay redirects to after the payment. */
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
                /**
                 * The saved configuration.
                 *
                 * @var array{WEBPAY_COMMERCE_CODE: string, WEBPAY_API_KEY: string, WEBPAY_ENVIRONMENT: 'integration'|'production', WEBPAY_RETURN_URL: string}
                 */
                'data' => $data
            ]
        );
    }

    /**
     * Get the upload settings
     *
     * Returns the maximum size of uploaded files, 50 MB until it is saved.
     */
    #[Group('Settings', weight: 20)]
    public function getUploadSettings(Request $request)
    {
        $uploadSettings = Siteinfo::where('key', 'upload_settings')->first();

        if (!$uploadSettings) {
            return response()->json([
                'data' => [
                    /**
                     * Maximum size of uploaded files, in MB.
                     *
                     * @var int
                     */
                    'max_upload_size' => 50, // Valor por defecto
                    /**
                     * Description of the setting.
                     *
                     * @var string
                     */
                    'content' => 'Configuración de tamaño máximo para subida de archivos'
                ]
            ]);
        }

        return response()->json([
            'data' => [
                /**
                 * Maximum size of uploaded files, in MB.
                 *
                 * @var int
                 */
                'max_upload_size' => $uploadSettings->value['max_upload_size'] ?? 50,
                /**
                 * Description of the setting.
                 *
                 * @var string
                 */
                'content' => $uploadSettings->content
            ]
        ]);
    }

    /**
     * Update the upload settings
     */
    #[Group('Settings', weight: 20)]
    public function updateUploadSettings(Request $request)
    {
        $data = $request->validate([
            /** Maximum size of uploaded files, in MB. */
            'max_upload_size' => 'required|integer|min:1|max:1024', // Máximo 1GB
        ]);

        $uploadSettings = Siteinfo::where('key', 'upload_settings')->first();
        $oldValue = $uploadSettings ? $uploadSettings->value : [];

        $value = [
            /**
             * Maximum size of uploaded files, in MB.
             *
             * @var int
             */
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
}
