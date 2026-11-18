@extends('layouts.console')
@section('title', 'My profile')
@section('breadcrumb', 'My profile')
@section('content')
<div class="page-heading"><div><h1 class="page-title">My profile</h1><p class="page-subtitle">Manage your owner account and sign-in details.</p></div></div>
<div class="profile-layout">
    <aside class="profile-summary panel">
        <span class="avatar profile-avatar">{{ mb_strtoupper(mb_substr($owner->name, 0, 1)) }}</span>
        <h2>{{ $owner->name }}</h2><p class="text-muted">{{ $owner->email }}</p>
        <span class="badge badge-blue">Workspace owner</span>
        <p class="field-hint">Your account manages buckets, access keys, and storage on this server.</p>
        <a href="{{ route('dashboard') }}" class="btn btn-secondary">Back to overview</a>
    </aside>
    <div class="profile-forms">
        <section class="panel" aria-labelledby="details-title">
            <div class="panel-header"><div><h2 class="panel-title" id="details-title">Account details</h2><p class="panel-subtitle">Your name appears in the console. Your email is used to sign in.</p></div></div>
            <form method="post" action="{{ route('profile.update') }}" class="panel-body profile-form">
                @csrf @method('PATCH')
                <div class="field"><label for="profile-name">Name</label><input class="input" id="profile-name" name="name" value="{{ old('name', $owner->name) }}" required maxlength="255" autocomplete="name"></div>
                <div class="field"><label for="profile-email">Email address</label><input class="input" type="email" id="profile-email" name="email" value="{{ old('email', $owner->email) }}" required maxlength="255" autocomplete="email"></div>
                <div class="field"><label for="identity-password">Current password</label><input class="input" type="password" id="identity-password" name="current_password" autocomplete="current-password" aria-describedby="identity-hint"><p class="field-hint" id="identity-hint">Required when changing your email address.</p></div>
                <div><button class="btn btn-primary" type="submit">Save profile</button></div>
            </form>
        </section>
        <section class="panel" aria-labelledby="password-title">
            <div class="panel-header"><div><h2 class="panel-title" id="password-title">Change password</h2><p class="panel-subtitle">Keep this account protected with a password you only use here.</p></div></div>
            <form method="post" action="{{ route('profile.password') }}" class="panel-body profile-form">
                @csrf @method('PUT')
                <div class="field"><label for="current-password">Current password</label><input class="input" type="password" id="current-password" name="current_password" required autocomplete="current-password"></div>
                <div class="form-grid">
                    <div class="field"><label for="new-password">New password</label><input class="input" type="password" id="new-password" name="password" required autocomplete="new-password"></div>
                    <div class="field"><label for="confirm-password">Confirm new password</label><input class="input" type="password" id="confirm-password" name="password_confirmation" required autocomplete="new-password"></div>
                </div>
                <p class="field-hint">You stay signed in here. Other signed-in sessions will need to sign in again after this change.</p>
                <div><button class="btn btn-primary" type="submit">Update password</button></div>
            </form>
        </section>
    </div>
</div>
@endsection
