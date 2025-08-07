<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Socomarca</title>
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
        padding-bottom: 20px;
      }
      .logo {
        max-width: 200px;
      }
      .btn {
        display: inline-block;
        padding: 12px 24px;
        margin: 24px 0;
        background-color: #6cb409;
        color: white;
        text-decoration: none;
        border-radius: 6px;
        font-weight: bold;
      }
      .footer {
        font-size: 12px;
        text-align: center;
        color: #999999;
        margin-top: 40px;
      }
      .footer a {
        color: #6cb409;
        text-decoration: none;
      }
      ul {
        padding-left: 20px;
      }
    </style>
  </head>
  <body>
    <div class="container">
      <div class="header">
        <img
          src="https://socomarca-frontend.vercel.app/assets/global/logo.png"
          alt="Socomarca Logo"
          class="logo"
        />
      </div>

      <h2>Hola {{ $user->name }},</h2>

      <h3>{{ $title }}</h3>


      <p>{{ $notificationMessage }}</p>

      <div style="text-align: center">
        <a href="https://socomarca-frontend.vercel.app/auth/login" class="btn">Ir a mi cuenta</a>
      </div>

      <p>
        Si tienes dudas o necesitas ayuda, nuestro equipo está disponible para
        ayudarte en todo momento.<br />
        Queremos que tu experiencia en Socomarca sea simple, confiable y a la
        altura de tus necesidades.
      </p>

      <p>
        Gracias por elegirnos.<br />
        Nos alegra acompañarte en cada compra.
      </p>

      <p>
        Saludos,<br />
        <strong style="color: #6cb409;">Equipo Socomarca</strong><br />
        <a href="https://socomarca.cl">socomarca.cl</a>
      </p>

      <div class="footer">
        © {{ date('Y') }} Socomarca. Todos los derechos reservados<br /><br />
        Recibes este correo porque estás registrado como cliente en Socomarca.<br />
        Si no deseas seguir recibiendo correos, puedes darte de baja haciendo clic aquí:<br />
        <a href="https://socomarca-frontend.vercel.app/auth/login">Cancelar suscripción</a>
      </div>
    </div>
  </body>
</html>