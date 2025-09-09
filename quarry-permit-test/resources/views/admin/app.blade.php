<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application {{ $tracking_id }}</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
    <div class="container">
        <div class="hero">
            <div class="hero__left">
                <h1 class="hero__title">Application</h1>
                <p class="hero__text">Tracking ID: <code style="cursor:pointer" onclick="navigator.clipboard.writeText('{{ $tracking_id }}')">{{ $tracking_id }}</code><br>
                Created: {{ $created_at ?? '—' }}</p>
            </div>
            <div class="hero__right">
                <h3 class="hero__subtitle">Progress</h3>
                <div class="progress"><div class="progress__bar" style="width: {{ $percent }}%"></div><span class="progress__text">{{ $percent }}% (Auto)</span></div>
                <a href="{{ route('admin.home') }}" class="hero__admin">Back to Dashboard</a>
            </div>
        </div>

        @if(session('status'))
            <div style="margin-top:12px; color:#166534; background:#dcfce7; padding:8px 12px; border-radius:8px;">{{ session('status') }}</div>
        @endif

        <div class="status-panel" style="margin-top:16px;">
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

        <div class="status-panel" style="margin-top:16px;">
            <h3 style="margin-top:0;">Form Fields</h3>
            <div class="status-grid">
                @php
                    $keys = count($placeholders) ? $placeholders : array_keys($form ?? []);
                @endphp
                @forelse($keys as $k)
                    <div class="label">{{ Str::of($k)->replaceMatches('/([A-Z])/', ' $1')->replace('_',' ')->ucfirst() }}</div>
                    <div class="value">{{ $form[$k] ?? '' }}</div>
                @empty
                    <div>No data yet.</div>
                @endforelse
            </div>
        </div>

        <div class="status-panel" style="margin-top:16px;">
            <h3 style="margin-top:0;">Admin Provided Files</h3>
            <div style="margin:8px 0;" id="adminUploadBar">
                <button type="button" onclick="adminTriggerRefUpload()">Upload Files for Applicant</button>
                <button type="button" onclick="adminDownloadDoc()">Download Filled .docx</button>
                <button type="button" onclick="adminTriggerPermit()">Upload Final Permit (.docx)</button>
            </div>
            <ul>
                @forelse(($admin_files ?? []) as $f)
                    <li><a href="{{ $f['url'] }}" target="_blank">{{ $f['name'] }}</a> ({{ round(($f['size'] ?? 0)/1024) }} KB)</li>
                @empty
                    <li>No admin files yet.</li>
                @endforelse
            </ul>
        </div>

        <div class="status-panel" style="margin-top:16px;">
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
        const res = await fetch('/api/application/admin-files/upload', { method:'POST', body: fd });
        if(!res.ok){ alert('Upload failed'); return; }
        location.reload();
      }
      async function adminDownloadDoc(){
        const data = @json($form ?? []);
        if(!data || Object.keys(data).length===0){ alert('No form data to generate.'); return; }
        const res = await fetch('/api/application/generate-doc', {
          method:'POST', headers:{ 'Content-Type':'application/json', 'Accept':'application/octet-stream' }, body: JSON.stringify(data)
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
        const res = await fetch('/api/application/permit/upload', { method:'POST', body: fd });
        const ok = res.ok; let msg = 'Uploaded';
        try{ const j = await res.json(); if(j?.message) msg=j.message; }catch(_){ }
        if(!ok){ alert(msg || 'Upload failed'); return; }
        // Auto-refresh to reflect 'Permit Available' checkbox and status
        location.reload();
      }
    </script>
</body>
</html>
