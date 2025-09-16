<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Home</title>
    <link rel="stylesheet" href="/styles.css">
    <style>
      /* Admin dashboard grid: adapt to content width and wrap long text */
      .admin-grid { grid-template-columns: max-content 1fr max-content minmax(180px, 1.6fr); align-items: start; }
      .admin-grid .value, .admin-grid .label { white-space: normal; word-break: break-word; }
    </style>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
      function clearSearch(){ const i = document.getElementById('q'); if(i){ i.value=''; i.form.submit(); } }
    </script>
  </head>
<body>
    <div class="container container--wide">
        <div class="hero">
            <div class="hero__left">
                <h1 class="hero__title">Admin Dashboard</h1>
                <p class="hero__text">Welcome, {{ session('username') }}. Review active applications below. Click a tracking ID to view details.</p>
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
            <h3 style="margin-top:0; display:flex; align-items:center; gap:12px; justify-content:space-between;">
                <span>Applications</span>
                <form method="GET" action="{{ route('admin.home') }}" style="display:flex; gap:8px; align-items:center;">
                    <input id="q" type="text" name="q" placeholder="Search applicant name" value="{{ request('q') }}" style="padding:8px 10px; border-radius:8px; border:1px solid #e5e7eb; min-width: 240px;">
                    <button type="submit" class="secondary" style="min-width:auto; padding:8px 12px;">Search</button>
                    @if(request('q'))
                      <button type="button" class="secondary" onclick="clearSearch()" style="min-width:auto; padding:8px 12px;">Clear</button>
                    @endif
                </form>
            </h3>
            @if(empty($apps))
                <p style="opacity:0.8;">No applications found.</p>
            @else
            <div class="status-grid admin-grid">
                <div class="label">Tracking ID</div>
                <div class="label">Created</div>
                <div class="label">Progress</div>
                <div class="label">Applicant Name</div>

                @foreach($apps as $a)
                    <div class="value">
                        <a href="{{ route('admin.app.show', ['trackingId' => $a['tracking_id']]) }}">
                            <code>{{ $a['tracking_id'] }}</code>
                        </a>
                    </div>
                    <div class="value">{{ $a['created_at'] ?? '—' }}</div>
                    <div class="value">{{ (int)($a['progress'] ?? 0) }}%</div>
                    <div class="value">{{ $a['applicant_name'] ?: '—' }}</div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</body>
</html>

