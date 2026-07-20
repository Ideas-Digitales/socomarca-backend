<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Contrase&ntilde;a Temporal</title>
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
        .password-box {
            background: #f8f9fa;
            border: 1px solid #e5e5e5;
            padding: 15px;
            margin: 24px 0;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            letter-spacing: 2px;
            color: #111111;
        }
        .note-block {
            margin: 24px 0;
            font-size: 14px;
            color: #333333;
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

        <h2 class="section-title">Hola, {{ $user->name }}</h2>

        <p>Hemos recibido una solicitud para restablecer tu contrase&ntilde;a. Te hemos generado una contrase&ntilde;a temporal que podr&aacute;s utilizar para acceder a tu cuenta:</p>

        <div class="password-box">
            {{ $temporaryPassword }}
        </div>

        <div class="note-block">
            <strong>IMPORTANTE:</strong> Por seguridad, te recomendamos cambiar esta contrase&ntilde;a temporal inmediatamente despu&eacute;s de iniciar sesi&oacute;n. El sistema te solicitar&aacute; cambiarla en tu pr&oacute;ximo inicio de sesi&oacute;n.
        </div>

        <p>Si no solicitaste el restablecimiento de tu contrase&ntilde;a, por favor contacta a nuestro equipo de soporte inmediatamente.</p>

        <p>
            Saludos cordiales,<br />
            Equipo de Socomarca
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
