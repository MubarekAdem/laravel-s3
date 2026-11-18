<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $r)
    {
        $data = $r->validate(['email' => ['required', 'email'], 'password' => ['required']]);
        $throttleKey = 'owner-login:'.hash('sha256', Str::lower($data['email']).'|'.$r->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back(fallback: route('login'))->withErrors(['email' => 'Too many login attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.'])->onlyInput('email');
        }
        if (! Auth::attempt($data, $r->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            return back(fallback: route('login'))->withErrors(['email' => 'Those credentials do not match our records.'])->onlyInput('email');
        }
        RateLimiter::clear($throttleKey);
        $r->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
