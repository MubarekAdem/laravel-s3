<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['owner' => $request->user(), 'activeView' => 'profile']);
    }

    public function update(Request $request): RedirectResponse
    {
        $owner = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($owner)],
            'current_password' => [Rule::requiredIf($request->input('email') !== $owner->email), 'nullable', 'current_password'],
        ]);
        $owner->fill(['name' => $data['name'], 'email' => $data['email']]);
        if ($owner->isDirty('email')) {
            $owner->email_verified_at = null;
        }
        $owner->save();

        return to_route('profile.edit')->with('status', 'Profile updated.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed'],
        ]);
        if (Hash::getDefaultDriver() === 'bcrypt' && (strlen($data['password']) > 72 || str_contains($data['password'], "\0"))) {
            throw ValidationException::withMessages(['password' => 'Bcrypt passwords must be at most 72 bytes and contain no null bytes.']);
        }
        $owner = $request->user();
        $owner->getConnection()->transaction(function () use ($owner, $data, $request): void {
            $owner->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $owner->getKey())->where('id', '!=', $request->session()->getId())->delete();
            }
        });
        $request->session()->regenerate();

        return to_route('profile.edit')->with('status', 'Password updated. Other signed-in sessions will need to sign in again.');
    }
}
