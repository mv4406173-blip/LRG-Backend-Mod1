<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function ensureIsNotRateLimited(): void
    {
        $emailKey = $this->throttleKey();
        $ipKey = $this->ipThrottleKey();

        $emailLimited = RateLimiter::tooManyAttempts($emailKey, 5);
        $ipLimited = RateLimiter::tooManyAttempts($ipKey, 20);

        if (! $emailLimited && ! $ipLimited) {
            return;
        }

        event(new Lockout($this));

        $seconds = max(
            RateLimiter::availableIn($emailKey),
            RateLimiter::availableIn($ipKey)
        );

        throw ValidationException::withMessages([
            'email' => ['Demasiados intentos. Inténtalo de nuevo en '.$seconds.' segundos.'],
        ]);
    }

    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey(), 60);
        RateLimiter::hit($this->ipThrottleKey(), 60);
    }

    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    public function ipThrottleKey(): string
    {
        return 'login-ip:'.$this->ip();
    }
}
