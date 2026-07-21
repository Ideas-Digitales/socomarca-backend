<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Bienvenido a Socomarca</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            color: #333333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            padding: 24px 0 16px;
        }
        .header img {
            max-width: 220px;
        }
        .divider {
            height: 4px;
            background-color: #6cb409;
            margin: 16px 0 32px;
        }
        .section-title {
            font-size: 18px;
            font-weight: bold;
            margin: 0 0 16px;
            color: #111111;
        }
        p {
            font-size: 14px;
            line-height: 1.6;
            color: #333333;
        }
        ul {
            padding-left: 20px;
            font-size: 14px;
            line-height: 1.6;
            color: #333333;
        }
        .btn-wrapper {
            text-align: center;
            margin: 24px 0;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #6cb409;
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
        }
        .footer {
            background-color: #6cb409;
            color: #ffffff;
            text-align: center;
            padding: 32px 20px;
            margin-top: 32px;
        }
        .footer .tagline {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 1px;
            margin-bottom: 20px;
        }
        .social {
            margin: 16px 0;
        }
        .social a {
            display: inline-block;
            margin: 0 6px;
            text-decoration: none;
        }
        .social a img {
            display: block;
            width: 36px;
            height: 36px;
            border: 0;
        }
        .footer .sent-by {
            font-size: 14px;
            margin-top: 16px;
        }
        .footer .contact {
            font-size: 13px;
            margin-top: 4px;
            opacity: 0.95;
        }
        a:link {
            color: green;
            background-color: transparent;
            text-decoration: none;
        }
        a:visited {
            color: pink;
            background-color: transparent;
            text-decoration: none;
        }
        a:hover {
            color: red;
            background-color: transparent;
            text-decoration: underline;
        }
        a:active {
            color: yellow;
            background-color: transparent;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    @php
        $logoUrl = \Illuminate\Support\Facades\Storage::disk('s3')->url('assets/logo.png');
        $iconBase = \Illuminate\Support\Facades\Storage::disk('s3')->url('assets/icons');
        $fromAddress = config('mail.from.address');
    @endphp

    <div class="container">
        <div class="header">
            <img src="{{ $logoUrl }}" alt="Socomarca Compra Rápida" />
        </div>
        <div class="divider"></div>

        <h2 class="section-title">Hola {{ $user->name }},</h2>

        <p>&iexcl;Gracias por registrarte en Socomarca!</p>

        <p>
            Ya eres parte de nuestra comunidad y podr&aacute;s acceder a cientos de productos mayoristas con precios convenientes, sin salir de tu negocio.
        </p>

        <p><strong>&iquest;Qu&eacute; puedes hacer desde tu cuenta?</strong></p>
        <ul>
            <li>Comprar f&aacute;cil y r&aacute;pido con despacho a domicilio</li>
            <li>Ver tu historial de pedidos</li>
            <li>Guardar productos favoritos</li>
            <li>Acceder a promociones exclusivas para usuarios registrados</li>
        </ul>

        <div class="btn-wrapper">
            <a href="https://socomarca-frontend.vercel.app/auth/login" class="btn">Ir a mi cuenta</a>
        </div>

        <p>
            Si tienes dudas o necesitas ayuda, nuestro equipo est&aacute; disponible para ayudarte en todo momento.<br />
            Queremos que tu experiencia en Socomarca sea simple, confiable y a la altura de tus necesidades.
        </p>

        <p>
            Gracias por elegirnos.<br />
            Nos alegra acompa&ntilde;arte en cada compra.
        </p>

        <div class="footer">
            <div class="tagline">Socomarca Compra Rápida</div>
            <div class="social">
                <a href="#" aria-label="Facebook"><img src="{{ $iconBase }}/facebook.png" width="36" height="36" alt="Facebook" /></a>
                <a href="#" aria-label="Twitter"><img src="{{ $iconBase }}/twitter.png" width="36" height="36" alt="Twitter" /></a>
                <a href="#" aria-label="Instagram"><img src="{{ $iconBase }}/instagram.png" width="36" height="36" alt="Instagram" /></a>
                <a href="#" aria-label="YouTube"><img src="{{ $iconBase }}/youtube.png" width="36" height="36" alt="YouTube" /></a>
                <a href="#" aria-label="Pinterest"><img src="{{ $iconBase }}/pinterest.png" width="36" height="36" alt="Pinterest" /></a>
            </div>
            <div class="sent-by">Este correo fue enviado por: <a href="mailto:{{ $fromAddress }}">{{ $fromAddress }}</a></div>
            <div class="contact">Por cualquier duda comunicarse a <a href="mailto:{{ $fromAddress }}">{{ $fromAddress }}</a></div>
        </div>
    </div>
</body>
</html>
