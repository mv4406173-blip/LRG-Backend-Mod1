<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# LRG — Módulo 1: API backend

Backend Laravel 12 para autenticación SPA, gestión de proyectos e importación de esquemas SQL versionados. Los SQL se guardan en el disco privado `local`; nunca se ejecutan contra la base central. La API JSON usa el prefijo `/api/v1`.

## Puesta en marcha

1. Copia `.env.example` a `.env`, configura `APP_KEY`, MySQL y el origen React en `FRONTEND_URL`.
2. Mantén `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database` y `CACHE_STORE=database` en desarrollo. En producción usa Redis para cola, caché y limitación de intentos compartida.
3. Ejecuta:

```sh
composer install
php artisan key:generate
php artisan migrate --seed
```

En desarrollo, abre procesos separados para el backend, el worker de cola y Vite (desde `lrg-frontend`):

```sh
php artisan serve --port=8001
php artisan queue:work --tries=3 --timeout=120
npm run dev
```

El worker debe permanecer activo para que las importaciones cambien de `queued` a `processing` y luego a `completed` o `failed`. Si no hay worker, los trabajos se acumulan en la tabla `jobs`.

El seeder local crea ocho usuarios, veinte proyectos, seis importaciones de muestra, cuatro artefactos y diagnósticos/eventos asociados. Es repetible y falla explícitamente en `APP_ENV=production`. Cuentas iniciales locales: `admin@lrg.local` / `Admin123456!` y `dev@lrg.local` / `Dev123456789!`; no usar estas credenciales fuera de desarrollo.

Ejecuta las pruebas con `php artisan test`. Para desarrollo, la configuración de Vite reenvía `/api` y `/sanctum` al backend en `localhost:8001`, evitando que el navegador envíe solicitudes con credenciales directamente a otro origen. Mantén `VITE_API_URL` vacío, `APP_URL=http://localhost:8001`, `FRONTEND_URL=http://localhost:5173` y el origen de React en `SANCTUM_STATEFUL_DOMAINS`.
En producción usa HTTPS, `SESSION_SECURE_COOKIE=true` y `APP_DEBUG=false`, además de secretos propios fuera del repositorio.

## Envío de correo con Gmail

Laravel envía los correos de verificación y recuperación mediante su transporte SMTP integrado (no requiere instalar una librería adicional). En `.env`, configura:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=tu-direccion@gmail.com
MAIL_PASSWORD=tu-contraseña-de-aplicación
MAIL_FROM_ADDRESS="${MAIL_USERNAME}"
MAIL_FROM_NAME="${APP_NAME}"
```

En la cuenta de Google activa la verificación en dos pasos y genera una contraseña de aplicación. Usa esa clave de 16 caracteres como `MAIL_PASSWORD`, no la contraseña normal de Gmail. No la compartas ni la guardes en el repositorio. Después de editar `.env`, ejecuta `php artisan config:clear`; Laravel enviará directamente a Gmail los correos nuevos, sin consultar `storage/logs/laravel.log`. La contraseña de aplicación y la dirección de Gmail son necesarias para autenticar el SMTP.

## Integración React con Sanctum

Usa cookies de sesión, nunca guardes tokens o contraseñas en `localStorage`. Para Axios:

```js
import axios from 'axios';

export const api = axios.create({
  baseURL: '',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
});

await api.get('/sanctum/csrf-cookie');
await api.post('/api/v1/auth/login', { email, password });
```

Después del inicio de sesión consulta `/api/v1/auth/me`. Los proyectos e importaciones requieren sesión autenticada y correo verificado. El enlace firmado de verificación se puede abrir directamente desde el correo sin iniciar sesión; es temporal y solo sirve para la dirección a la que se envió. El registro envía el enlace de verificación mediante el mailer configurado.

## Rutas principales

| Método | Ruta | Acceso |
|---|---|---|
| POST | `/api/v1/auth/register`, `/api/v1/auth/login` | Público, limitado |
| POST | `/api/v1/auth/logout`, `/api/v1/auth/confirm-password`; GET `/api/v1/auth/me` | Sesión |
| POST | `/api/v1/auth/forgot-password`, `/api/v1/auth/reset-password` | Público, limitado |
| POST | `/api/v1/auth/email/verification-notification`; GET `/api/v1/auth/email/verify/{id}/{hash}` | Sesión / enlace firmado |
| GET, POST | `/api/v1/projects` | Propietario |
| GET, PATCH, DELETE | `/api/v1/projects/{project}` | Propietario |
| GET, POST | `/api/v1/projects/{project}/schema-imports` | Propietario |
| GET | `/api/v1/projects/{project}/schema-imports/{import}` | Propietario |
| POST | `/api/v1/projects/{project}/schema-imports/{import}/retry` | Propietario |
| GET | `/api/v1/projects/{project}/schema-artifacts` | Propietario |
| GET | `/api/v1/projects/{project}/schema-artifacts/{artifact}` | Propietario |

La importación es `multipart/form-data` con `file` (`.sql`, hasta 10 MiB) y `dialect`. Responde `202` con `data.id` y `data.status`; consulta la ruta de estado hasta `completed` o `failed`. Solo una importación válida publica una nueva versión. Los listados devuelven `data` y `meta` de paginación; todas las fechas usan ISO 8601 y no se exponen rutas internas.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
