<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in · Harbor Storage</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; background: #091827; color: #e0f2fe; font: 16px/1.5 system-ui; }
        form { box-sizing: border-box; width: min(424px, calc(100vw - 32px)); margin: 24px 0; padding: 32px; background: #10263a; border: 1px solid #24445f; border-radius: 14px; }
        h1 { font-size: 28px; letter-spacing: -.03em; margin-top: 0; } p { color: #b9c8d8; }
        label { display: block; } input { width: 100%; box-sizing: border-box; margin: 6px 0 18px; padding: 11px; background: #07131f; color: white; border: 1px solid #355b79; border-radius: 7px; font: inherit; }
        button { background: #2563eb; color: #fff; border: 0; border-radius: 7px; padding: 11px 16px; font: inherit; font-weight: 700; cursor: pointer; }
        button:focus-visible, input:focus-visible { outline: 3px solid #67e8f9; outline-offset: 2px; } .error { color: #fca5a5; }
    </style>
</head>
<body>
<form method="post">
    <h1>Harbor Storage</h1><p>Sign in to manage this server.</p>
    @csrf
    <label>Email<input name="email" type="email" autocomplete="username" required autofocus value="{{ old('email') }}"></label>
    <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
    @if ($errors->any())<div role="alert">@foreach ($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach</div>@endif
    <button>Sign in</button>
    <p><a href="{{ route('home') }}">Home</a> · <a href="{{ route('api.docs') }}">API docs</a></p>
</form>
</body>
</html>
