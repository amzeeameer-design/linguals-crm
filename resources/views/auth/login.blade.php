<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · The Linguals</title><link rel="icon" type="image/png" href="{{ asset('branding/linguals-favicon.png') }}"><link rel="apple-touch-icon" href="{{ asset('branding/linguals-favicon.png') }}"><link rel="stylesheet" href="{{ asset('workspace.css') }}"></head>
<body><div class="login-page"><section class="login-art"><div class="brand"><img class="brand-logo" src="{{ asset('branding/linguals-logo.png') }}" alt="The Linguals"></div><div><div class="eyebrow">LANGUAGE HAS NO LIMITS</div><h1>A world of opportunity.<br>A place for your talent.</h1><p>Connect your expertise with a global community. Manage your journey, one clear step at a time.</p><div class="hero-art" aria-hidden="true"><div class="orbit"></div><div class="orbit two"></div><div class="center">L</div><span class="floating a">Aa</span><span class="floating b">文</span></div></div><small>Connecting language, connecting people.</small></section><section class="login-form"><img class="auth-mobile-brand" src="{{ asset('branding/linguals-logo.png') }}" alt="The Linguals"><div class="eyebrow">WELCOME BACK</div><h1>Sign in to your workspace</h1><p>Your talent journey continues here.</p>
@if(session('notice'))
    <div class="notice" role="status">
        {{ session('notice') }}
    </div>
@endif

@if($errors->any())
    <div class="notice" role="alert">
        @foreach($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif
<form method="post" action="{{ url('login') }}">@csrf<label class="field">Email address<input type="email" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="you@example.com"></label><label class="field">Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn primary" type="submit">Sign in →</button></form><a href="{{ route('password.request') }}" class="btn text" style="margin-top:14px">Forgot your password?</a><p style="font-size:11px;margin-top:25px">Need access? Contact your talent onboarding team.</p></section></div></body></html>
