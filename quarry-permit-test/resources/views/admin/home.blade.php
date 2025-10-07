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
  <div class="admin-frame">
    <aside class="admin-sidebar">
      <div class="brand">Quarry Admin</div>
      <div class="sub">{{ session('username') }}</div>
      <nav class="nav">
        <a href="{{ route('admin.home') }}" class="active">Dashboard</a>
        <a href="{{ route('admin.export') }}">Export CSV</a>
        <a href="/">Back to Site</a>
        <form method="POST" action="{{ route('admin.logout') }}">
          @csrf
          <button type="submit">Logout</button>
        </form>
      </nav>
    </aside>
    <main class="admin-main">
      <div class="container container--wide">
        @php
          $apps = $apps ?? [];
          $total = count($apps);
          $newApps = 0; $inProgress = 0; $statusCounts = [];
          foreach ($apps as $a){
            $status = strtolower((string)($a['status'] ?? 'submitted'));
            $p = (int)($a['progress'] ?? 0);
            if (($status === '' || $status === 'submitted') && $p === 0) { $newApps++; }
            if ($p > 0 && $p < 100 && !in_array($status, ['denied','approved'])) { $inProgress++; }
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
          }
          arsort($statusCounts);
        @endphp

        <form class="admin-search" method="GET" action="{{ route('admin.home') }}">
          <input id="q" type="text" name="q" placeholder="Search applicant or tracking ID" value="{{ request('q') }}">
          <button type="submit" class="secondary">Search</button>
          @if(request('q'))
            <button type="button" class="secondary" onclick="clearSearch()">Clear</button>
          @endif
        </form>

        <section class="dash-cards">
          <div class="dash-card clickable" data-filter="all">
            <div class="title">Total Applications</div>
            <div class="value">{{ number_format($total) }}</div>
            <div class="hint">All submitted items</div>
          </div>
          <div class="dash-card clickable {{ ($newApps ?? 0) > 0 ? 'dash-card--alert' : '' }}" data-filter="new" data-card="new-apps" data-count="{{ $newApps }}">
            <div class="title">New Applications</div>
            <div class="value">{{ number_format($newApps) }}</div>
            <div class="hint">Submitted, not yet acknowledged</div>
          </div>
          <div class="dash-card clickable" data-filter="inprogress">
            <div class="title">In Progress Applications</div>
            <div class="value">{{ number_format($inProgress) }}</div>
            <div class="hint">Being processed by the office</div>
          </div>
        </section>

        <div class="status-panel" style="margin-top: 0;">
          <h3 id="statusPanelTitle" style="margin: 0 0 10px;">Total Applications</h3>
          @if($total === 0)
            <p style="opacity:.8;">No applications to show.</p>
          @else
            <div id="miniChart" class="mini-chart">
              @foreach($statusCounts as $label => $count)
                @php $pct = max(0, min(100, $count / max(1,$total) * 100)); @endphp
                <div class="bar-row clickable" data-filter="status" data-status="{{ strtolower($label ?: 'submitted') }}">
                  <div class="bar-label">{{ $label ?: 'submitted' }}</div>
                  <div class="bar-count">{{ $count }}</div>
                  <div class="bar"><div class="fill" style="width: {{ number_format($pct, 2, '.', '') }}%"></div></div>
                </div>
              @endforeach
            </div>
          @endif
        </div>
        

        <div id="appsPanel" class="status-panel" style="margin-top:16px;">
            <h3 style="margin-top:0; margin-bottom:8px;">Applications</h3>
            @if(empty($apps))
                <p style="opacity:0.8;">No applications found.</p>
            @else
            <div>
              <div class="admin-list-header">
                <div>Tracking ID</div>
                <div>Applicant</div>
                <div>Created</div>
                <div>Status</div>
                <div>Progress</div>
                <div></div>
              </div>
              <div id="appsRows" class="apps-rows">
                @foreach($apps as $a)
                  @php
                    $status = strtolower((string)($a['status'] ?? 'submitted'));
                    $p = (int)($a['progress'] ?? 0);
                    $isNew = (($status === '' || $status === 'submitted') && $p === 0) ? 1 : 0;
                  @endphp
                  <div class="app-row" data-status="{{ $status }}" data-progress="{{ $p }}" data-new="{{ $isNew }}">
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
                    <div class="value actions-cell">
                      <div class="kebab">
                        <button type="button" class="kebab__btn" aria-haspopup="true" aria-expanded="false" title="More">⋮</button>
                        <div class="kebab__menu hidden" role="menu">
                          <form method="POST" action="{{ route('admin.app.delete', ['trackingId' => $a['tracking_id']]) }}" onsubmit="return confirm('Delete application {{ $a['tracking_id'] }} permanently?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="kebab__item kebab__danger" role="menuitem">Delete</button>
                          </form>
                        </div>
                      </div>
                    </div>
                  </div>
                @endforeach
              </div>
            </div>
            @endif
        </div>
      </div>
    </main>
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

      // Simple client-side filtering for the apps list
      (function(){
        function applyFilter(kind, value){
          const rows = document.querySelectorAll('#appsRows .app-row');
          const filtered = [];
          rows.forEach(row => {
            const status = (row.getAttribute('data-status')||'').toLowerCase();
            const progress = parseInt(row.getAttribute('data-progress')||'0',10);
            const isNew = row.getAttribute('data-new') === '1';
            let show = true;
            if (kind === 'all') show = true;
            else if (kind === 'new') show = isNew;
            else if (kind === 'inprogress') show = (progress > 0 && progress < 100 && !['denied','approved'].includes(status));
            else if (kind === 'status') show = (status === String(value||'').toLowerCase());
            row.style.display = show ? 'grid' : 'none';
            if (show) filtered.push({status, progress});
          });
          const title = document.getElementById('statusPanelTitle');
          if (title){
            if (kind === 'all') title.textContent = 'Total Applications';
            else if (kind === 'new') title.textContent = 'New Applications';
            else if (kind === 'inprogress') title.textContent = 'In Progress Applications';
            else if (kind === 'status') title.textContent = (String(value||'').trim() || 'Applications').replace(/^./, c=>c.toUpperCase()) + ' Applications';
          }

          // Rebuild mini chart to reflect current filtered set
          const chart = document.getElementById('miniChart');
          if (chart){
            // Count by status
            const counts = {};
            filtered.forEach(({status}) => { const k = status || 'submitted'; counts[k] = (counts[k]||0) + 1; });
            const total = filtered.length;
            chart.innerHTML = '';
            if (total === 0){
              const p = document.createElement('p'); p.style.opacity = '.8'; p.textContent = 'No applications to show.'; chart.appendChild(p);
            } else {
              // Render sorted by count desc
              Object.entries(counts).sort((a,b)=>b[1]-a[1]).forEach(([label, count])=>{
                const pct = Math.max(0, Math.min(100, (count/Math.max(1,total))*100));
                const row = document.createElement('div'); row.className = 'bar-row clickable'; row.setAttribute('data-filter','status'); row.setAttribute('data-status', label);
                const l = document.createElement('div'); l.className='bar-label'; l.textContent = label || 'submitted';
                const c = document.createElement('div'); c.className='bar-count'; c.textContent = String(count);
                const bar = document.createElement('div'); bar.className='bar';
                const fill = document.createElement('div'); fill.className='fill'; fill.style.width = pct.toFixed(2) + '%';
                bar.appendChild(fill);
                row.appendChild(l); row.appendChild(c); row.appendChild(bar);
                row.addEventListener('click', ()=> applyFilter('status', label));
                chart.appendChild(row);
              });
            }
          }
          const panel = document.getElementById('appsPanel');
          if (panel) panel.scrollIntoView({behavior:'smooth', block:'start'});
        }
        // Bind cards
        document.querySelectorAll('.dash-card.clickable').forEach(card=>{
          card.addEventListener('click', ()=> applyFilter(card.getAttribute('data-filter')||'all'));
        });
        // Bind status bars
        document.querySelectorAll('.bar-row.clickable').forEach(row=>{
          row.addEventListener('click', ()=> applyFilter('status', row.getAttribute('data-status')));
        });

        // Kebab menu toggles
        function closeAllKebabs(){
          document.querySelectorAll('.kebab__menu').forEach(m => { m.classList.add('hidden'); m.classList.remove('open'); });
          document.querySelectorAll('.kebab').forEach(k => k.classList.remove('kebab--open'));
          document.querySelectorAll('.app-row').forEach(r => r.classList.remove('open'));
        }
        document.querySelectorAll('.kebab__btn').forEach(btn => {
          btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const menu = btn.parentElement.querySelector('.kebab__menu');
            const isOpen = menu && menu.classList.contains('open');
            closeAllKebabs();
            if (menu){
              if (!isOpen) {
                menu.classList.remove('hidden');
                menu.classList.add('open');
                const kebab = btn.closest('.kebab'); if (kebab) kebab.classList.add('kebab--open');
                const row = btn.closest('.app-row'); if (row) row.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
                // Reposition as fixed so it overlays all stacking contexts
                try {
                  const rect = btn.getBoundingClientRect();
                  // measure actual menu width now that it's open; fallback to CSS min-width
                  const menuW = Math.max(160, menu.offsetWidth || 0);
                  // position relative to viewport (fixed): do NOT add scroll offsets
                  const top = Math.round(rect.bottom + 6);
                  let left = Math.round(rect.right - menuW);
                  // clamp within viewport
                  const maxLeft = Math.max(8, window.innerWidth - menuW - 8);
                  if (left > maxLeft) left = maxLeft;
                  if (left < 8) left = 8;
                  Object.assign(menu.style, { position:'fixed', top: top+'px', left: left+'px', right:'auto', zIndex: 5000 });
                } catch(_) {}
              } else {
                btn.setAttribute('aria-expanded', 'false');
              }
            }
          });
        });
        document.addEventListener('click', closeAllKebabs);
      })();
    </script>
</body>
</html>
