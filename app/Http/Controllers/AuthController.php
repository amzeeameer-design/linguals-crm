<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function forgot(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:254']);
        \App\Services\PortalSettings::apply();
        abort_unless(config('mail.default') === 'smtp', 503, 'Email recovery is not configured. Please contact the talent team.');
        try {
            Password::sendResetLink($data);
        } catch (\Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['email' => 'Recovery delivery is temporarily unavailable. Please try again later.']);
        }

        return back()->with('notice', 'If an account matches this address, a password reset link will arrive shortly.');
    }

    public function reset(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['token' => 'required|string', 'email' => 'required|email', 'password' => 'required|string|min:12|max:200|confirmed']);
        $status = Password::reset($data, function (\App\Models\User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This recovery link is invalid or expired. Request a new link.']);
        }

        return redirect()->route('login')->with('notice', 'Password reset. Sign in with your new password.');
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($d)) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }$r->session()->regenerate();
        if (! in_array($r->user()->role, ['admin', 'applicant'], true)) {
            Auth::logout();
            abort(403);
        }

        return $r->user()->role === 'admin'
            ? redirect()->intended(url('workspace'))
            : redirect()->route('portal');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
