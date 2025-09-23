<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Home</title>
    <link rel="stylesheet" href="/styles.css">
    <style>
      /* Admin dashboard grid: 5 columns now (ID, Applicant, Created, Status, Progress) */
      .admin-grid { grid-template-columns: max-content minmax(200px, 1.6fr) max-content max-content max-content; align-items: start; }
      .admin-grid .value, .admin-grid .label { white-space: normal; word-break: break-word; }
      .admin-grid code { font-weight: 700; }
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
                <a href="{{ route('admin.export') }}" class="hero__admin" style="margin-left:auto;">Export CSV</a>
            </h3>
            @if(empty($apps))
                <p style="opacity:0.8;">No applications found.</p>
            @else
            <div class="status-grid admin-grid">
                <div class="label">Tracking ID</div>
                <div class="label">Applicant</div>
                <div class="label">Created</div>
                <div class="label">Status</div>
                <div class="label">Progress</div>

                @foreach($apps as $a)
                    <div class="value">
                        <a href="{{ route('admin.app.show', ['trackingId' => $a['tracking_id']]) }}">
                            <code>{{ $a['tracking_id'] }}</code>
                        </a>
                    </div>
                    <div class="value">{{ $a['applicant_name'] ?: '—' }}</div>
                    <div class="value">{{ $a['created_fmt'] ?? ($a['created_at'] ?? '—') }}</div>
                    <div class="value">
                        <span class="badge {{ $a['status_class'] ?? '' }}">{{ $a['status'] ?? '—' }}</span>
                        @if(isset($a['latest_fee']) && is_numeric($a['latest_fee']))
                          <span class="badge ok" style="margin-left:6px;">₱ {{ number_format((float)$a['latest_fee'],2) }}</span>
                        @endif
                    </div>
                    <div class="value"><span class="badge {{ $a['progress_badge'] ?? '' }}">{{ (int)($a['progress'] ?? 0) }}%</span></div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
    <script>
      (function(){
        fetch('/api/v2/applications',{headers:{'Accept':'application/json'}})
          .then(r=>r.ok?r.json():Promise.resolve({items:[]}))
          .then(j=>{
            const items = Array.isArray(j.items)? j.items : [];
            const grid = document.getElementById('appsV2');
            const empty = document.getElementById('appsV2Empty');
            if (!items.length){
              empty.textContent = 'No database-backed applications yet.';
              return;
            }
            empty.style.display='none';
            items.forEach(it=>{
              const td1 = document.createElement('div'); td1.className='value'; td1.textContent = it.tracking_id || '-';
              const td2 = document.createElement('div'); td2.className='value'; td2.textContent = (it.created_at||'').toString().replace('T',' ').replace('Z','');
              const td3 = document.createElement('div'); td3.className='value'; td3.textContent = it.status || '-';
              const td4 = document.createElement('div'); td4.className='value'; td4.textContent = [it.municipality, it.province].filter(Boolean).join(', ');
              grid.appendChild(td1); grid.appendChild(td2); grid.appendChild(td3); grid.appendChild(td4);
            });
          }).catch(()=>{
            const empty = document.getElementById('appsV2Empty');
            if (empty) empty.textContent = 'Unable to load applications.';
          });
      })();
    </script>
</body>
</html>
