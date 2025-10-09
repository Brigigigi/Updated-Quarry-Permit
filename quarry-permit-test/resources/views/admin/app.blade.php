<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Application {{ $tracking_id }}</title>
    <link rel="stylesheet" href="/styles.css">
<style>
  /* minor tweaks for section anchors */
  .section-anchor { scroll-margin-top: 16px; }
  .nav__action { display:block; width:100%; margin-top:8px; padding:8px 12px; background:#1d4ed8; color:#fff; border:none; border-radius:6px; text-align:center; cursor:pointer; font-weight:600; }
  .nav__action:hover { background:#1e40af; }
  .admin-files-list { list-style:none; padding:0; margin:0; display:grid; gap:8px; }
  .admin-files-list__item { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
  .admin-file__meta { margin-left:8px; font-size:0.85rem; color:#4b5563; }
  .admin-file__actions button { margin-left:6px; }
</style>
</head>
<body>
  <div class="admin-frame">
    <aside class="admin-sidebar">
      <div class="brand">Quarry Admin</div>
      <div class="sub">{{ session('username') }}</div>
      <nav class="nav">
        <a href="{{ route('admin.home') }}">Dashboard</a>
        <a href="#overview">Overview</a>
        <a href="#review">Admin Review</a>
        <a href="#fees">Fee Assessment</a>
        <a href="#inspection">Inspection</a>
        <a href="#bond">Bond</a>
        <a href="#board">Board Action</a>
        <a href="#permit">Grant Permit</a>
        <a href="#form">Form Fields</a>
        <a href="#adminfiles">Admin Files</a>
        <button type="button" class="nav__action" onclick="adminDownloadDoc()">Generate Permit</button>
        <a href="#files">Applicant Files</a>
      </nav>
    </aside>
    <main class="admin-main">
    <div class="container container--wide">
        <div class="hero">
            <div class="hero__left">
                <h1 class="hero__title">Application</h1>
                <p class="hero__text">Tracking ID: <code style="cursor:pointer" onclick="navigator.clipboard.writeText('{{ $tracking_id }}')">{{ $tracking_id }}</code><br>
                Created: {{ $created_at ?? '—' }}</p>
            </div>
            <div class="hero__right">
                <h3 class="hero__subtitle">Progress</h3>
                <div class="progress"><div class="progress__bar" style="width: {{ $percent }}%"></div><span class="progress__text">{{ $percent }}% (Auto)</span></div>
            </div>
        </div>

        @if(session('status'))
            <div style="margin-top:12px; color:#166534; background:#dcfce7; padding:8px 12px; border-radius:8px;">{{ session('status') }}</div>
        @endif

        

        @if(!empty($db['bond']) || !empty($db['board']) || !empty($db['permit']))
        <div id="overview" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Current Records (DB)</h3>
            <div class="status-grid" style="grid-template-columns: 1fr 2fr;">
                @if(!empty($db['bond']))
                    <div class="label">Bond</div>
                    <div class="value">Type: {{ $db['bond']->type ?? '' }}, Amount: {{ $db['bond']->amount ?? '' }}, Status: {{ $db['bond']->status ?? '' }}</div>
                @endif
                @if(!empty($db['board']))
                    <div class="label">Board Action</div>
                    <div class="value">Resolution: {{ $db['board']->resolution ?? '' }}
                        @if(!empty($db['board']->decided_at))
                          @php
                            try { $fmt = \Carbon\Carbon::parse($db['board']->decided_at)->format('Y-m-d H:i'); }
                            catch (\Throwable $e) { $fmt = $db['board']->decided_at; }
                          @endphp
                          ({{ $fmt }})
                        @endif
                    </div>
                @endif
                @if(!empty($db['permit']))
                    <div class="label">Permit</div>
                    <div class="value">No: {{ $db['permit']->permit_no ?? '' }}, Term: {{ $db['permit']->term_start ?? '' }} → {{ $db['permit']->term_end ?? '' }}, Status: {{ $db['permit']->status ?? '' }}</div>
                @endif
                @if(!empty($db['fees']) && count($db['fees']))
                    <div class="label">Latest Fees</div>
                    <div class="value">₱ {{ number_format((float)($db['fees'][0]->total_amount ?? 0),2) }} @if(!empty($db['fees'][0]->or_no)) (OR: {{ $db['fees'][0]->or_no }}) @endif</div>
                @endif
                @if(!empty($db['payment']))
                    <div class="label">Payment</div>
                    <div class="value">Method: {{ $db['payment']->method ?? '' }}, Ref: {{ $db['payment']->reference ?? '' }} @if(!empty($db['payment']->paid_at)) ({{ (new \Carbon\Carbon($db['payment']->paid_at))->format('Y-m-d H:i') }}) @endif</div>
                @endif
            </div>
        </div>
        @endif

        <div id="review" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Admin Review</h3>
            <form method="POST" action="{{ route('admin.app.update', ['trackingId'=>$tracking_id]) }}" style="display:grid; gap:10px;">
                @csrf
                <div style="display:grid; grid-template-columns: 1fr 2fr; gap:10px 16px;">
                    <div class="label">Fields Checked</div>
                    <div><input type="checkbox" name="fields_ok" {{ ($adminStatus['checks']['fields_ok'] ?? false) ? 'checked' : '' }}> All required fields verified</div>
                    <div class="label">Files Verified</div>
                    <div><input type="checkbox" name="files_ok" {{ ($adminStatus['checks']['files_ok'] ?? false) ? 'checked' : '' }}> Required documents complete</div>
                    <div class="label">References</div>
                    <div><input type="checkbox" name="references_ok" {{ ($adminStatus['checks']['references_ok'] ?? false) ? 'checked' : '' }}> References validated</div>
                    <div class="label">Permit Available</div>
                    <div><input type="checkbox" name="permit_available" {{ ($adminStatus['permit_available'] ?? false) ? 'checked' : '' }}> Final permit ready for applicant</div>
                </div>

                <h4>Sign-offs</h4>
                <div class="status-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                    <div class="label">Role</div>
                    <div class="label">Signed</div>
                    <div class="label">Name</div>
                    <div class="label">Date</div>

                    @php $roles = ['chair'=>'Chair','secretary'=>'Secretary','governor'=>'Governor','mayor'=>'Mayor','barangay_captain'=>'Barangay Captain']; @endphp
                    @foreach($roles as $key=>$label)
                        @php $row = $adminStatus['signoffs'][$key] ?? ['signed'=>false,'name'=>'','date'=>null]; @endphp
                        <div class="value">{{ $label }}</div>
                        <div class="value"><input type="checkbox" name="sign_{{ $key }}" {{ $row['signed'] ? 'checked' : '' }}></div>
                        <div class="value"><input type="text" name="name_{{ $key }}" value="{{ $row['name'] }}"></div>
                        <div class="value">{{ $row['date'] ?? '—' }}</div>
                    @endforeach
                </div>

                <div class="actions" style="margin-top:12px;">
                    <label for="note">Admin Note to Applicant</label>
                    <textarea name="note" id="note" rows="3" placeholder="Add a note visible to the applicant.">{{ $adminStatus['note'] ?? '' }}</textarea>
                    <button type="submit">Save Status</button>
                </div>
            </form>
        </div>

        <!-- Fee Assessment -->
        <div id="fees" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Fee Assessment</h3>
            @php
                $editingFee = $editFee ?? null;
                $currentFeeId = old('fee_id', $editingFee->id ?? '');
                $oldItemsJson = old('items_json');
                $feeItemsPreset = [];
                if (is_string($oldItemsJson) && $oldItemsJson !== '') {
                    $decodedOld = json_decode($oldItemsJson, true);
                    if (is_array($decodedOld)) {
                        $feeItemsPreset = $decodedOld;
                    }
                } elseif ($editingFee) {
                    $feeItemsPreset = $editingFee->items_json ?? [];
                }
                $feeItemsJson = json_encode($feeItemsPreset ?: []);
            @endphp
            @if($errors->first('fees'))
              <div class="note-box" style="border-color:#ef4444; color:#991b1b; background:#fee2e2;">{{ $errors->first('fees') }}</div>
            @endif
            @if($editingFee)
              <div class="note-box" style="background:#dbeafe; border-color:#3b82f6; color:#1e3a8a; display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <span>Editing fee assessment recorded {{ optional($editingFee->created_at)->format('Y-m-d H:i') ?? 'previously' }}.</span>
                <a href="{{ route('admin.app.show', ['trackingId'=>$tracking_id]) }}#fees" class="secondary">Start new</a>
              </div>
            @endif
            <form id="feeForm" method="POST" action="{{ route('admin.app.fees', ['trackingId'=>$tracking_id]) }}" style="display:grid; gap:10px;">
                @csrf
                <input type="hidden" name="fee_id" value="{{ $currentFeeId }}">
                <div class="status-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                    <div class="label">Total Amount</div>
                    <div class="value"><input type="number" id="feeTotal" name="total_amount" step="0.01" required value="{{ old('total_amount', $editingFee->total_amount ?? '') }}"></div>
                    <div class="label">OR No.</div>
                    <div class="value"><input type="text" name="or_no" value="{{ old('or_no', $editingFee->or_no ?? '') }}"></div>
                    <div class="label">Notes</div>
                    <div class="value" style="grid-column: span 3;"><input type="text" name="notes" value="{{ old('notes', $editingFee->notes ?? '') }}"></div>
                    <div class="label">Items</div>
                    <div class="value" style="grid-column: span 3;">
                      <table id="feeItemsTable" style="width:100%; border-collapse:collapse;">
                        <thead>
                          <tr><th style="text-align:left; padding:6px;">Label</th><th style="text-align:right; padding:6px;">Amount</th><th></th></tr>
                        </thead>
                        <tbody></tbody>
                      </table>
                      <button type="button" class="secondary" id="addFeeItem" style="margin-top:8px;">Add Item</button>
                      <input type="hidden" name="items_json" id="items_json" value="{{ old('items_json', $feeItemsJson) }}">
                    </div>
                </div>
                <div class="actions" style="margin-top:12px;">
                    <button type="submit">{{ $editingFee ? 'Update Fee Assessment' : 'Save Fee Assessment' }}</button>
                </div>
            </form>
            @if(!empty($db['fees']) && count($db['fees']))
                <div class="status-grid" style="grid-template-columns: 1fr 2fr; margin-top:10px;">
                    <div class="label">History</div>
                    <div class="value">
                      <div class="fee-history">
                        @foreach($db['fees'] as $fee)
                          @php
                              $isActive = (string)$currentFeeId === (string)$fee->id;
                          @endphp
                          <div class="fee-history__row" style="display:flex; align-items:center; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border); {{ $loop->last ? 'border-bottom:none;' : '' }} {{ $isActive ? 'font-weight:600;' : '' }}">
                            <div>PHP {{ number_format((float)($fee->total_amount ?? 0),2) }}
                              @if(!empty($fee->or_no))
                                <span style="margin-left:6px; color:#4b5563;">OR: {{ $fee->or_no }}</span>
                              @endif
                              <span style="margin-left:6px; color:#6b7280;">{{ optional($fee->created_at)->format('Y-m-d H:i') ?? 'n/a' }}</span>
                              @if(!empty($fee->notes))
                                <div style="color:#4b5563; font-size:0.85em;">{{ $fee->notes }}</div>
                              @endif
                            </div>
                            <a class="secondary" href="{{ route('admin.app.show', ['trackingId'=>$tracking_id]) }}?edit_fee={{ $fee->id }}#fees">{{ $isActive ? 'Editing' : 'Edit' }}</a>
                          </div>
                        @endforeach
                      </div>
                    </div>
                </div>
            @endif
        </div>

        <script>
      const ADMIN_TRACKING_ID = @json($tracking_id);
      window.ADMIN_TRACKING_ID = ADMIN_TRACKING_ID;
      const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
          (function(){
            const table = document.getElementById('feeItemsTable');
            if (!table) { return; }
            const tbody = table.querySelector('tbody');
            const addBtn = document.getElementById('addFeeItem');
            const totalEl = document.getElementById('feeTotal');
            const hidden = document.getElementById('items_json');
            const form = document.getElementById('feeForm');
            const preset = (function() {
              if (hidden && hidden.value) {
                try {
                  const parsed = JSON.parse(hidden.value);
                  if (Array.isArray(parsed) && parsed.length) {
                    return parsed;
                  }
                } catch (e) {}
              }
              return @json($feeItemsPreset);
            })();

            function addRow(label = '', amount = ''){
              const tr = document.createElement('tr');
              tr.innerHTML = `
                <td style="padding:6px; border-top:1px solid var(--border);"><input type="text" class="fee-label" value="${label}" placeholder="Description" style="width:100%"></td>
                <td style="padding:6px; border-top:1px solid var(--border); text-align:right;"><input type="number" class="fee-amount" step="0.01" value="${amount}" placeholder="0.00" style="width:120px; text-align:right;"></td>
                <td style="padding:6px; border-top:1px solid var(--border); text-align:right;"><button type="button" class="secondary remove">Remove</button></td>
              `;
              tbody.appendChild(tr);
              const labelInput = tr.querySelector('.fee-label');
              const amountInput = tr.querySelector('.fee-amount');
              tr.querySelector('.remove').onclick = () => { tr.remove(); recompute(); };
              if (labelInput) labelInput.addEventListener('input', recompute);
              if (amountInput) amountInput.addEventListener('input', recompute);
            }

            function recompute(){
              const rows = Array.from(tbody.querySelectorAll('tr'));
              let total = 0; const items = [];
              rows.forEach(r => {
                const label = (r.querySelector('.fee-label')?.value || '').trim();
                const amtVal = r.querySelector('.fee-amount')?.value || '';
                const amt = parseFloat(amtVal === '' ? '0' : amtVal);
                if (label || (!isNaN(amt) && amt !== 0)) {
                    items.push({ label, amount: isNaN(amt) ? 0 : amt });
                }
                if (!isNaN(amt)) {
                    total += amt;
                }
              });
              if (totalEl) {
                totalEl.value = total.toFixed(2);
              }
              if (hidden) {
                hidden.value = JSON.stringify(items);
              }
            }

            if (addBtn) addBtn.onclick = () => { addRow(); };
            if (form) form.addEventListener('submit', () => { recompute(); });

            if (Array.isArray(preset) && preset.length) {
              preset.forEach(item => {
                const label = typeof item?.label === 'string' ? item.label : '';
                const amount = item && typeof item.amount !== 'undefined' ? item.amount : '';
                addRow(label, amount);
              });
              recompute();
            } else {
              addRow();
            }
          })();
        </script>

        <!-- Inspection -->
        <div id="inspection" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Inspection</h3>
            @if($errors->first('inspection'))
              <div class="note-box" style="border-color:#ef4444; color:#991b1b; background:#fee2e2;">{{ $errors->first('inspection') }}</div>
            @endif
            <form method="POST" action="{{ route('admin.app.inspection', ['trackingId'=>$tracking_id]) }}" style="display:grid; gap:10px;">
                @csrf
                <div class="status-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                    <div class="label">Scheduled At</div>
                    <div class="value"><input type="datetime-local" name="scheduled_at" required value="{{ old('scheduled_at') }}"></div>
                    <div class="label">Inspected At</div>
                    <div class="value"><input type="datetime-local" name="inspected_at" value="{{ old('inspected_at') }}"></div>
                    <div class="label">Notes</div>
                    <div class="value" style="grid-column: span 3;"><input type="text" name="notes" value="{{ old('notes') }}"></div>
                </div>
                <div class="actions" style="margin-top:12px;">
                    <button type="submit">Add Inspection</button>
                </div>
            </form>
            @if(!empty($db['inspections']) && count($db['inspections']))
                <div class="status-grid" style="grid-template-columns: 1fr 2fr; margin-top:10px;">
                    @foreach($db['inspections'] as $ins)
                      @php
                        try { $sched = \Carbon\Carbon::parse($ins->scheduled_at)->format('Y-m-d H:i'); } catch (\Throwable $e) { $sched = $ins->scheduled_at; }
                        $done = $ins->inspected_at ? ( (new \Carbon\Carbon($ins->inspected_at))->format('Y-m-d H:i') ) : '—';
                      @endphp
                      <div class="label">{{ $sched }}</div>
                      <div class="value">Done: {{ $done }} — {{ $ins->notes ?? '' }}</div>
                    @endforeach
                </div>
            @endif
        </div>
        <!-- Bond -->
        <div id="bond" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Bond</h3>
            @if($errors->first('bond'))
              <div class="note-box" style="border-color:#ef4444; color:#991b1b; background:#fee2e2;">{{ $errors->first('bond') }}</div>
            @endif
            <form method="POST" action="{{ route('admin.app.bond', ['trackingId'=>$tracking_id]) }}" style="display:grid; gap:10px;">
                @csrf
                <div class="status-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                    <div class="label">Type</div>
                    <div class="value"><input type="text" name="type" value="{{ old('type', $db['bond']->type ?? '') }}" required></div>
                    <div class="label">Amount</div>
                    <div class="value"><input type="number" step="0.01" name="amount" value="{{ old('amount', $db['bond']->amount ?? '') }}" required></div>

                    <div class="label">Issuer</div>
                    <div class="value"><input type="text" name="issuer" value="{{ old('issuer', $db['bond']->issuer ?? '') }}"></div>
                    <div class="label">Policy No.</div>
                    <div class="value"><input type="text" name="policy_no" value="{{ old('policy_no', $db['bond']->policy_no ?? '') }}"></div>

                    <div class="label">Issue Date</div>
                    <div class="value"><input type="date" name="issue_date" value="{{ old('issue_date', $db['bond']->issue_date ?? '') }}"></div>
                    <div class="label">Expiry Date</div>
                    <div class="value"><input type="date" name="expiry_date" value="{{ old('expiry_date', $db['bond']->expiry_date ?? '') }}"></div>

                    <div class="label">Status</div>
                    <div class="value">
                        @php $bondStatus = old('status', $db['bond']->status ?? 'pending'); @endphp
                        <select name="status" required>
                            <option value="pending" {{ $bondStatus==='pending' ? 'selected' : '' }}>pending</option>
                            <option value="active" {{ $bondStatus==='active' ? 'selected' : '' }}>active</option>
                            <option value="expired" {{ $bondStatus==='expired' ? 'selected' : '' }}>expired</option>
                            <option value="released" {{ $bondStatus==='released' ? 'selected' : '' }}>released</option>
                        </select>
                    </div>
                    <div class="label">File URL</div>
                    <div class="value"><input type="text" name="file_url" placeholder="optional link" value="{{ old('file_url', $db['bond']->file_url ?? '') }}"></div>
                </div>
                <div class="actions" style="margin-top:12px;">
                    <button type="submit">Save Bond</button>
                </div>
            </form>
        </div>

        <!-- Board Action -->
        <div id="board" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Board Action</h3>
            @if($errors->first('board'))
              <div class="note-box" style="border-color:#ef4444; color:#991b1b; background:#fee2e2;">{{ $errors->first('board') }}</div>
            @endif
            <form method="POST" action="{{ route('admin.app.board', ['trackingId'=>$tracking_id]) }}" style="display:grid; gap:10px;">
                @csrf
                <div class="status-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                    <div class="label">Meeting No.</div>
                    <div class="value"><input type="text" name="meeting_no" value="{{ old('meeting_no') }}"></div>
                    <div class="label">Resolution</div>
                    <div class="value">
                        @php $res = old('resolution'); @endphp
                        <select name="resolution" required>
                            <option value="approve" {{ $res==='approve' ? 'selected' : '' }}>approve</option>
                            <option value="rework" {{ $res==='rework' ? 'selected' : '' }}>rework</option>
                            <option value="deny" {{ $res==='deny' ? 'selected' : '' }}>deny</option>
                        </select>
                    </div>
                    <div class="label">Minutes URL</div>
                    <div class="value" style="grid-column: span 3;"><input type="text" name="minutes_url" value="{{ old('minutes_url') }}"></div>
                </div>
                <div class="actions" style="margin-top:12px;">
                    <button type="submit">Record Board Action</button>
                </div>
            </form>
        </div>

        <!-- Grant Permit -->
        <div id="permit" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Grant Permit</h3>
            @if($errors->first('permit'))
              <div class="note-box" style="border-color:#ef4444; color:#991b1b; background:#fee2e2;">{{ $errors->first('permit') }}</div>
            @endif
            @if(empty($db['permit']))
            <form method="POST" action="{{ route('admin.app.grant', ['trackingId'=>$tracking_id]) }}" style="display:grid; gap:10px;">
                @csrf
                <div class="status-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                    <div class="label">Permit No.</div>
                    <div class="value"><input type="text" name="permit_no" value="{{ old('permit_no') }}" required></div>
                    <div class="label">Term Start</div>
                    <div class="value"><input type="date" name="term_start" value="{{ old('term_start') }}" required></div>
                </div>
                <div class="actions" style="margin-top:12px;">
                    <button type="submit">Grant Permit</button>
                </div>
                <p style="opacity:0.85; font-size:12px;">Requires: Active bond and Board approval. Term end auto-sets to 5 years after start.</p>
            </form>
            @else
              <p style="opacity:0.9;">Permit already granted: <strong>{{ $db['permit']->permit_no }}</strong> ({{ $db['permit']->term_start }} → {{ $db['permit']->term_end }})</p>
            @endif
        </div>

        <div id="form" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Form Fields</h3>
            <div class="status-grid">
                @php
                    $keys = $formDisplayKeys ?? (count($placeholders) ? $placeholders : array_keys($form ?? []));
                @endphp
                @forelse($keys as $k)
                    @php
                        $label = Str::of($k)->replaceMatches('/([A-Z])/', ' $1')->replace('_', ' ')->ucfirst();
                        $value = $formDisplayValues[$k] ?? ($form[$k] ?? '');
                    @endphp
                    <div class="label">{{ $label }}</div>
                    <div class="value">{{ $value }}</div>
                @empty
                    <div>No data yet.</div>
                @endforelse
            </div>
        </div>

        <div id="adminfiles" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Admin Provided Files</h3>
            <div style="margin:8px 0;" id="adminUploadBar">
                <button type="button" onclick="adminTriggerRefUpload()">Upload Files for Applicant</button>
                <button type="button" onclick="adminDownloadDoc()">Download Filled .docx</button>
                <button type="button" onclick="adminTriggerPermit()">Upload Final Permit (.docx)</button>
            </div>
            <ul class="admin-files-list">
                @forelse(($admin_files ?? []) as $f)
                    <li class="admin-files-list__item">
                        <div class="admin-file__info">
                            <a href="{{ $f['url'] }}" target="_blank">{{ $f['name'] }}</a>
                            <span class="admin-file__meta">({{ round(($f['size'] ?? 0)/1024) }} KB)</span>
                        </div>
                        <div class="admin-file__actions">
                            <button type="button" data-file="{{ $f['name'] }}" onclick="adminRenameFile(this.dataset.file)">Rename</button>
                            <button type="button" data-file="{{ $f['name'] }}" onclick="adminDeleteFile(this.dataset.file)">Delete</button>
                        </div>
                    </li>
                @empty
                    <li>No admin files yet.</li>
                @endforelse
            </ul>
        </div>

        <div id="files" class="status-panel section-anchor" style="margin-top:16px;">
            <h3 style="margin-top:0;">Applicant Uploaded Files</h3>
            <ul>
                @forelse($files as $f)
                    <li><a href="{{ $f['url'] }}" target="_blank">{{ $f['name'] }}</a> ({{ round(($f['size'] ?? 0)/1024) }} KB)</li>
                @empty
                    <li>No applicant files.</li>
                @endforelse
            </ul>
        </div>
    </div>
    </main>
  </div>
    <script>
      function adminTriggerRefUpload(){
        let input = document.getElementById('adminRefUploadFiles');
        if(!input){
          input = document.createElement('input');
          input.type = 'file'; input.id='adminRefUploadFiles'; input.multiple = true; input.style.display='none';
          input.addEventListener('change', () => adminUploadRefFiles(input.files));
          document.getElementById('adminUploadBar').appendChild(input);
        }
        input.value=''; input.click();
      }
      async function adminUploadRefFiles(fileList){
        const files = fileList || (document.getElementById('adminRefUploadFiles')?.files || []);
        if(!files || files.length===0){ alert('Select files first'); return; }
        const fd = new FormData();
        fd.append('tracking_id', @json($tracking_id));
        Array.from(files).forEach(f => fd.append('files[]', f));
        const res = await fetch('/api/application/admin-files/upload', { method:'POST', body: fd, credentials:'same-origin' });
        if(!res.ok){ alert('Upload failed'); return; }
        location.reload();
      }

      async function adminRenameFile(name){
        const current = typeof name === 'string' ? name : '';
        const proposed = prompt('Enter new file name', current);
        if (proposed === null) { return; }
        const trimmed = proposed.trim();
        if (!trimmed || trimmed === current) { return; }
        try {
          const res = await fetch('/api/application/admin-files/rename', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            credentials: 'same-origin',
            body: JSON.stringify({ tracking_id: ADMIN_TRACKING_ID, name: current, new_name: trimmed })
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) {
            alert(data.message || 'Rename failed');
            return;
          }
          location.reload();
        } catch (err) {
          console.error(err);
          alert('Rename failed');
        }
      }

      async function adminDeleteFile(name){
        if (!name) { return; }
        if (!confirm('Delete ' + name + '?')) { return; }
        try {
          const res = await fetch('/api/application/admin-files/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            credentials: 'same-origin',
            body: JSON.stringify({ tracking_id: ADMIN_TRACKING_ID, name })
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) {
            alert(data.message || 'Delete failed');
            return;
          }
          location.reload();
        } catch (err) {
          console.error(err);
          alert('Delete failed');
        }
      }
      async function adminDownloadDoc(){
        const formData = @json($form ?? []);
        const payload = {
          ...formData,
          tracking_id: @json($tracking_id),
          trackingId: @json($tracking_id),
        };
        if(!payload || Object.keys(payload).length===0){ alert('No form data to generate.'); return; }
        const res = await fetch('/api/application/generate-doc', {
          method:'POST', headers:{ 'Content-Type':'application/json', 'Accept':'application/octet-stream' }, body: JSON.stringify(payload)
        });
        if(!res.ok){ alert('Failed to generate document'); return; }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download='application-{{ $tracking_id }}.docx'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
      }

      function adminTriggerPermit(){
        let input = document.getElementById('adminPermitFile');
        if(!input){
          input = document.createElement('input');
          input.type='file'; input.id='adminPermitFile'; input.style.display='none';
          input.addEventListener('change', () => adminUploadPermit(input.files?.[0]));
          document.getElementById('adminUploadBar').appendChild(input);
        }
        input.value=''; input.click();
      }
      async function adminUploadPermit(file){
        const f = file || (document.getElementById('adminPermitFile')?.files?.[0]);
        if(!f){ alert('Select a file'); return; }
        const fd = new FormData();
        fd.append('tracking_id', @json($tracking_id));
        fd.append('permit', f);
        const res = await fetch('/api/application/permit/upload', { method:'POST', body: fd, credentials:'same-origin' });
        const ok = res.ok; let msg = 'Uploaded';
        try{ const j = await res.json(); if(j?.message) msg=j.message; }catch(_){ }
        if(!ok){ alert(msg || 'Upload failed'); return; }
        // Auto-refresh to reflect 'Permit Available' checkbox and status
        location.reload();
      }
    </script>
    <script>
      // Sidebar: show only the clicked section
      (function(){
        const sectionIds = ['overview','review','fees','inspection','bond','board','permit','form','adminfiles','files'];
        const panels = new Map(sectionIds.map(id => [id, document.getElementById(id)]));
        const links = Array.from(document.querySelectorAll('.admin-sidebar .nav a[href^="#"]'));

        function setActive(id){
          links.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + id));
        }

        function showOnly(id){
          panels.forEach((el, key) => { if (el) el.style.display = (key === id) ? '' : 'none'; });
          setActive(id);
        }

        function placeDefaultPair(){
          const over = document.getElementById('overview');
          const form = document.getElementById('form');
          if (over && form){
            if (over.parentElement && over.parentElement.id === 'defaultPair') return; // already grouped
            const container = document.createElement('div');
            container.className = 'admin-two-col';
            container.id = 'defaultPair';
            const parent = over.parentNode;
            parent.insertBefore(container, over);
            container.appendChild(over);
            container.appendChild(form);
          }
        }

        function showDefault(){
          // Show overview + form together if both exist; else show whichever exists
          const over = document.getElementById('overview');
          const form = document.getElementById('form');
          panels.forEach((el) => { if (el) el.style.display = 'none'; });
          if (over) over.style.display = '';
          if (form) form.style.display = '';
          // no active highlight for combined view
        }

        links.forEach(a => {
          a.addEventListener('click', (e) => {
            const href = a.getAttribute('href') || '';
            if (!href.startsWith('#')) return;
            e.preventDefault();
            const target = href.slice(1);
            if (panels.has(target)){
              showOnly(target);
              history.replaceState(null, '', '#' + target);
            }
          });
        });

        // Arrange overview + form side-by-side for default
        placeDefaultPair();

        const initial = (location.hash || '').slice(1);
        if (panels.has(initial)) {
          showOnly(initial);
        } else {
          showDefault();
        }
      })();
    </script>
</body>
</html>









