<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Home</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
    <div class="container">
        <div class="hero">
            <div class="hero__left">
                <h1 class="hero__title">Admin Dashboard</h1>
                <p class="hero__text">Welcome, {{ session('username') }}. Review active applications below. Click a tracking ID to copy it, then use the public tracker or load it in the form.</p>
            </div>
            <div class="hero__right">
                <h3 class="hero__subtitle">Actions</h3>
                <a href="/" class="hero__btn" style="text-align:center;">Back to Site</a>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="hero__btn">Logout</button>
                </form>
                <a class="hero__admin" href="/admin/login">Switch Account</a>
            </div>
        </div>

        <div class="status-panel" style="margin-top:16px;">
            <h3 style="margin-top:0;">Applications</h3>
            @if(empty($apps))
                <p style="opacity:0.8;">No applications started yet.</p>
            @else
            <div class="status-grid" style="grid-template-columns: 1.4fr 1fr 1fr 1fr;">
                <div class="label">Tracking ID</div>
                <div class="label">Created</div>
                <div class="label">Fields Filled</div>
                <div class="label">Files</div>

                @foreach($apps as $a)
                    <div class="value">
                        <a href="{{ route('admin.app.show', ['trackingId' => $a['tracking_id']]) }}">
                            <code>{{ $a['tracking_id'] }}</code>
                        </a>
                    </div>
                    <div class="value">{{ $a['created_at'] ?? '—' }}</div>
                    <div class="value">{{ $a['fields_filled'] }}</div>
                    <div class="value">{{ $a['files_uploaded'] }}</div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</body>
</html>
