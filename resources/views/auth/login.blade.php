<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#091827">
    <title>Sign in · Harbor Storage</title>
    <link rel="stylesheet" href="{{ asset('css/harbor-auth.css') }}">
</head>
<body class="harbor-auth">
    <a class="auth-skip" href="#sign-in">Skip to sign in</a>
    <aside class="auth-brand-panel" aria-label="Harbor Storage">
        <a class="auth-brand" href="{{ route('home') }}" aria-label="Harbor Storage home">
            <svg viewBox="0 0 32 36" width="30" height="34" fill="none" aria-hidden="true">
                <path d="M4 7v20l12 6 12-6V7L16 1 4 7Z" stroke="currentColor" stroke-width="2"/>
                <path d="M10 11v14m12-14v14M10 18h12" stroke="currentColor" stroke-width="3"/>
            </svg>
            <span>Harbor <span class="auth-brand-detail">Storage</span></span>
        </a>
        <div class="auth-story">
            <h2>A home for<br>what you build.</h2>
            <p>Object storage on your own infrastructure.<br class="auth-desktop-break"> Your buckets, your keys, your Harbor.</p>
            <svg class="auth-warehouse" viewBox="0 0 560 340" fill="none" aria-hidden="true">
                <defs>
                    <pattern id="warehouse-slats" width="12" height="12" patternUnits="userSpaceOnUse">
                        <path d="M0 12V0" stroke="#314b60" stroke-width="1"/>
                    </pattern>
                </defs>
                <path d="m36 242 232-126 256 132-232 76Z" fill="#102639" stroke="#294458"/>
                <path d="m58 253 232 57 211-66M84 267l230-125M118 280l230-120M153 291l229-112" stroke="#223b4e"/>
                <path d="M117 214V114l164-67 154 81v110l-164 57Z" fill="#142e43" stroke="#597487"/>
                <path d="m117 114 154 73 164-59-154-81Z" fill="#203d52" stroke="#708b9d"/>
                <path d="m117 114 154 73v108l-154-81Z" fill="#19354a" stroke="#597487"/>
                <path d="m271 187 164-59v110l-164 57Z" fill="url(#warehouse-slats)" stroke="#597487"/>
                <path d="m131 110 141 65 148-49M148 103l140 65M170 94l140 65M192 85l140 65M214 76l140 65M236 67l140 65M258 58l140 65" stroke="#436176"/>
                <path d="M143 226v-78l43 21v77m16 8v-77l43 20v78" fill="#0c2031" stroke="#5a778c"/>
                <path d="m149 163 31 15m-31-2 31 15m-31-2 31 15m-31-2 31 15m-31-2 31 15m28-35 31 15m-31-2 31 15m-31-2 31 15m-31-2 31 15m-31-2 31 15" stroke="#314e64"/>
                <path d="m294 209 40-14v47l-40 14Zm59-21 40-14v47l-40 14Z" fill="#0b1d2d" stroke="#58798f"/>
                <path d="m301 218 26-9m-26 21 26-9m33-24 26-9m-26 21 26-9" stroke="#67e8f9" stroke-width="2"/>
                <path d="m265 86 24-10 25 12-24 10Zm0 0v20l25 12V98m24-10v20l-24 10" stroke="#94b3c7"/>
                <path d="m82 244 23-12 26 13-23 12Zm0 0v24l26 13v-24m23-12v24l-23 12" fill="#1d3d52" stroke="#6b8da3"/>
                <path d="m437 267 25-9m-11-14v29M53 215l16-8m-8-4v16" stroke="#54768e"/>
                <path d="m175 303 116 29 119-39" stroke="#67e8f9" stroke-opacity=".5"/>
            </svg>
        </div>
        <dl class="auth-facts">
            <div><dt>Deployment</dt><dd>Self-hosted</dd></div>
            <div><dt>Interface</dt><dd>S3 API subset</dd></div>
        </dl>
    </aside>
    <main class="auth-workspace" id="sign-in" tabindex="-1">
        <div class="auth-form-wrap">
            <div class="auth-context">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true">
                    <rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.5"/>
                    <path d="m8 9 3 3-3 3m5 0h3" stroke="currentColor" stroke-width="1.5"/>
                </svg>
                Owner console
            </div>
            <section class="auth-card" aria-labelledby="login-title">
                <header class="auth-form-heading">
                    <h1 id="login-title">Welcome aboard.</h1>
                    <p>Sign in to manage this storage server.</p>
                </header>
                <form method="POST">
                    @csrf
                    @if ($errors->any())
                        <div class="auth-errors" id="login-errors" role="alert">
                            <p>Unable to sign in</p>
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="auth-field">
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" autocomplete="username" required autofocus value="{{ old('email') }}" @if ($errors->has('email')) aria-invalid="true" aria-describedby="login-errors" @endif>
                    </div>
                    <div class="auth-field">
                        <label for="password">Password</label>
                        <div class="auth-password">
                            <input id="password" name="password" type="password" autocomplete="current-password" required @if ($errors->has('password')) aria-invalid="true" aria-describedby="login-errors" @endif>
                            <button class="auth-password-toggle" type="button" aria-controls="password" aria-pressed="false" hidden>Show password</button>
                        </div>
                    </div>
                    <button class="auth-submit" type="submit">Sign in</button>
                </form>
                <p class="auth-owner-note">Use the owner account configured for this server.</p>
            </section>
            <nav class="auth-links" aria-label="Useful links">
                <a href="{{ route('home') }}">Harbor home</a>
                <a href="{{ route('api.docs') }}">API documentation</a>
            </nav>
        </div>
        <p class="auth-workspace-note">Your infrastructure. Your objects.</p>
    </main>
    <script>
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.querySelector('.auth-password-toggle');

        passwordToggle.hidden = false;
        passwordToggle.addEventListener('click', () => {
            const isVisible = passwordInput.type === 'password';
            passwordInput.type = isVisible ? 'text' : 'password';
            passwordToggle.setAttribute('aria-pressed', String(isVisible));
            passwordToggle.textContent = isVisible ? 'Hide password' : 'Show password';
        });
    </script>
</body>
</html>
