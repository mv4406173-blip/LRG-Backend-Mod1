<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\SecurityEventService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly SecurityEventService $securityEvents
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password,
        ]);

        event(new Registered($user));

        $this->securityEvents->record('register', $user->id, $request);

        return response()->json([
            'message' => 'Cuenta creada. Revisa tu correo para verificar tu dirección.',
            'data'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        if (! Auth::attempt($request->only('email', 'password'), false)) {
            $request->hitRateLimiter();

            $this->securityEvents->record('login_failed', null, $request, [
                'email_hint' => substr($request->string('email'), 0, 3).'***',
            ]);

            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        $request->clearRateLimiter();
        $request->session()->regenerate();

        $user = Auth::user();

        $this->securityEvents->record('login_success', $user->id, $request);

        return response()->json([
            'data' => [
                'id'                => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->securityEvents->record('logout', $request->user()->id, $request);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id'                => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at'        => $user->created_at->toIso8601String(),
            ],
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        $this->securityEvents->record('password_reset_requested', null, $request);

        return response()->json([
            'message' => 'Si existe una cuenta con ese correo, recibirás un enlace de recuperación.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request): void {
                $user->forceFill([
                    'password' => $password,
                ])->setRememberToken(Str::random(60));
                $user->save();

                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();

                event(new PasswordReset($user));
                $this->securityEvents->record('password_reset_completed', $user->id, $request);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['No se pudo restablecer la contraseña. El enlace puede ser inválido o haber expirado.'],
            ]);
        }

        return response()->json(['message' => 'Contraseña restablecida correctamente.']);
    }

    public function confirmPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($request->password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['La contraseña no es correcta.'],
            ]);
        }

        $request->session()->passwordConfirmed();

        $this->securityEvents->record('password_confirmed', $request->user()->id, $request);

        return response()->json(['message' => 'Contraseña confirmada.']);
    }

    public function sendVerification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'El correo ya está verificado.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Enlace de verificación enviado.']);
    }

    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($request->user() && (int) $request->user()->getKey() !== $id) {
            abort(403, 'El enlace de verificación no es válido.');
        }

        if (! hash_equals((string) sha1($user->getEmailForVerification()), (string) $hash)) {
            abort(403, 'El enlace de verificación no es válido.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            $this->securityEvents->record('email_verified', $user->id, $request);
        }

        return response()->json(['message' => 'Correo verificado correctamente.']);
    }
}
