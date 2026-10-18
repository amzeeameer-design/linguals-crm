<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#176b59">
    <title>The Linguals · Talent workspace</title><link rel="icon" type="image/png" href="{{ asset('branding/linguals-favicon.png') }}"><link rel="apple-touch-icon" href="{{ asset('branding/linguals-favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('workspace.css') }}?v={{ filemtime(public_path('workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('talent-search.css') }}?v={{ filemtime(public_path('talent-search.css')) }}">
<link rel="stylesheet" href="{{ asset('application-journey.css') }}">
<script>window.LINGUALS_BASE_URL = {{ Illuminate\Support\Js::from(url('/')) }};</script><script src="{{ asset('deployment.js') }}?v={{ filemtime(public_path('deployment.js')) }}"></script><link rel="manifest" href="{{ asset('manifest.webmanifest') }}"><meta name="theme-color" content="#176b59"><meta name="apple-mobile-web-app-capable" content="yes"><link rel="apple-touch-icon" href="{{ asset('branding/app-icon-192.png') }}"><link rel="stylesheet" href="{{ asset('portal-install.css') }}"><link rel="stylesheet" href="{{ asset('content-refresh.css') }}?v={{ filemtime(public_path('content-refresh.css')) }}"><link rel="stylesheet" href="{{ asset('workspace-ui.css') }}?v={{ filemtime(public_path('workspace-ui.css')) }}"></head>
<body>
    <div id="app"><div class="workspace-loading" role="status"><strong>Opening your workspace</strong><p>Your talent network is loading.</p><noscript>Enable JavaScript to open the workspace.</noscript></div></div>
    <script>
    window.lingualsWorkspaceLoadFailed = function () {
        const root = document.getElementById('app');
        if (!root || root.querySelector('.shell')) return;
        root.replaceChildren();
        const panel = document.createElement('div');
        panel.className = 'workspace-loading';
        panel.setAttribute('role', 'alert');
        const title = document.createElement('strong');
        title.textContent = 'Your workspace could not load';
        const message = document.createElement('p');
        message.textContent = 'Refresh this page. If the problem continues, contact your administrator.';
        const button = document.createElement('button');
        button.textContent = 'Refresh workspace';
        button.addEventListener('click', function () { location.reload(); });
        panel.append(title, message, button);
        root.append(panel);
    };
    </script>
    <div id="toast" role="status" aria-live="polite"></div>
    <div id="modal-root"></div>
    <script>window.LINGUALS = {{ Illuminate\Support\Js::from($workspace) }};</script>
    <script src="{{ asset('talent-search.js') }}?v={{ filemtime(public_path('talent-search.js')) }}" defer onerror="window.lingualsWorkspaceLoadFailed()"></script>
<script src="{{ asset('workspace.js') }}?v={{ filemtime(public_path('workspace.js')) }}" defer onerror="window.lingualsWorkspaceLoadFailed()"></script>
<script src="{{ asset('portal-install.js') }}" defer onerror="window.lingualsWorkspaceLoadFailed()"></script></body>
</html>
