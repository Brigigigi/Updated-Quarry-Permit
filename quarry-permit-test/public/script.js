let checklistInitialized = false;
// Configure API base. Leave empty when using php artisan serve.
// If serving the HTML statically (e.g., Live Server), set to your Laravel URL, e.g. 'http://localhost:8000'.
const API_BASE = '';

// Fallback list used when template placeholders are unavailable
const DEFAULT_APP_FIELDS = [
    // Page 1
    'headerProvinceCity','isagNo','addressedProvinceCity','applicationDate',
    'applicantName','applicantAddress','cubicMeters','approxAreaHectares',
    'sitio','barangay','municipality','province','island',
    // Page 2
    'feeAmount','bondType','bondAmount','applicantSignatureName','applicantTin',
    // Page 3
    'ackProvince','ackCityMunicipality','notaryPlace','notaryDay','notaryMonth','notaryYear',
    'ctcNo','ctcIssuedAt','ctcIssuedDay','ctcIssuedMonth','ctcIssuedYear','notaryUntilYear','ptrNo',
    'docNo','pageNo','bookNo','seriesOf'
];

// ------------------- PAGE NAVIGATION -------------------
function togglePages(showId) {
    const pages = ['registerPage','loginPage','menuPage','formPageUser','formPageAdmin','formApplicationUser','trackPage','appActionsPage'];
    pages.forEach(id => document.getElementById(id).classList.toggle('hidden', id !== showId));
}

function showRegister() { togglePages('registerPage'); }
function showLogin() { togglePages('loginPage'); }
function showMenu() { togglePages('menuPage'); }

function showForm() {
    const role = sessionStorage.getItem('currentRole');
    togglePages(role==='admin' ? 'formPageAdmin' : 'formPageUser');
    if(role==='admin' && !checklistInitialized){
        initChecklist();
        checklistInitialized = true;
        loadChecklist();
        updateProgress();
    }
    if(role==='user') loadApplication();
}

function showApplicationForm() {
    togglePages('formApplicationUser');
    ensureTrackingId().then(() => ensurePlaceholders(true)).then(() => {
        buildApplicationForm(window.APP_PLACEHOLDERS || []);
        loadApplication();
        showTrackingWarning();
    });
}

function showAppActions(){
    // Auto-save before moving to actions page
    saveApplication(true).finally(() => {
        togglePages('appActionsPage');
    });
}

function showTrack(){
    togglePages('trackPage');
    const tid = sessionStorage.getItem('tracking_id') || '';
    const input = document.getElementById('trackIdInput');
    if (tid && input && !input.value) input.value = tid;
    if (input && input.value.trim()) { checkStatus(); }
}

// ------------------- HELPER FUNCTIONS -------------------
function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

async function safeFetch(url, options = {}) {
    try {
        const res = await fetch(`${API_BASE}${url}`, {
            ...options,
            headers: {
                'X-CSRF-TOKEN': getCsrfToken(),
                'Accept': 'application/json',
                ...(options.headers || {})
            }
        });

        const text = await res.text();
        let data;
        try { data = text ? JSON.parse(text) : {}; } 
        catch(e) { console.error('Invalid JSON response:', text); throw new Error('Server returned invalid JSON'); }

        if (!res.ok) throw new Error(data.message || 'Server error');
        return data;
    } catch(err) {
        alert(err.message);
        throw err;
    }
}

// ------------------- AUTH -------------------
async function register() {
    const username = document.getElementById('regUser').value.trim();
    const password = document.getElementById('regPass').value.trim();
    const role = document.getElementById('regRole').value;
    if(!username || !password){ alert('Please fill out all fields'); return; }

    await safeFetch('/api/register', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({username, password, role})
    });

    alert('Registered successfully! Please login.');
    showLogin();
}

async function login() {
    const username = document.getElementById('loginUser').value.trim();
    const password = document.getElementById('loginPass').value.trim();
    if(!username || !password){ alert('Please fill out all fields'); return; }

    const data = await safeFetch('/api/login',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({username, password})
    });

    sessionStorage.setItem('currentRole', data.role);
    sessionStorage.setItem('username', username);
    document.getElementById('appFormBtn').style.display = data.role==='admin' ? 'none' : 'inline-block';
    showMenu();
}

async function logout() {
    await safeFetch('/api/logout', { method: 'POST' });
    sessionStorage.removeItem('currentRole');
    showLogin();
}

// ------------------- ADMIN CHECKLIST -------------------
function initChecklist() {
    document.querySelectorAll('#checklistForm input[type="checkbox"]')
        .forEach(cb => cb.addEventListener('change', () => {
            saveChecklist(false);
            updateProgress();
        }));
}

async function saveChecklist(showAlert = true) {
    const items = Array.from(document.querySelectorAll('#checklistForm input[type="checkbox"]'))
        .map(cb => cb.checked);

    await safeFetch('/api/checklist/save', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            username: sessionStorage.getItem('username') || '',
            items
        })
    });

    if(showAlert) alert('Checklist progress saved!');
}

async function loadChecklist() {
    const username = encodeURIComponent(sessionStorage.getItem('username') || '');
    const data = await safeFetch(`/api/checklist/load?username=${username}`);
    document.querySelectorAll('#checklistForm input[type="checkbox"]')
        .forEach((cb,i) => cb.checked = !!data.items[i]);
    updateProgress();
}

function updateProgress() {
    const boxes = document.querySelectorAll('#checklistForm input[type="checkbox"]');
    const checked = Array.from(boxes).filter(cb => cb.checked).length;
    const percent = boxes.length ? Math.round((checked/boxes.length)*100) : 0;
    const bar = document.getElementById('progressBar');
    const text = document.getElementById('progressText');
    bar.style.width = percent+'%';
    text.textContent = percent+'% complete';
}

// ------------------- APPLICANT APPLICATION FORM -------------------
async function saveApplication() {
    const fields = (window.APP_PLACEHOLDERS && window.APP_PLACEHOLDERS.length)
        ? window.APP_PLACEHOLDERS
        : DEFAULT_APP_FIELDS;
    const data = {};
    fields.forEach(f => data[f] = document.getElementById(f).value);

    const tracking_id = sessionStorage.getItem('tracking_id') || '';
    await safeFetch('/api/application/save',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ tracking_id, ...data })
    });

    alert('Application form saved as draft!');
}

async function loadApplication() {
    const tracking_id = encodeURIComponent(sessionStorage.getItem('tracking_id') || '');
    if(!tracking_id){ return; }
    const result = await safeFetch(`/api/application/load?tracking_id=${tracking_id}`);
    if(result.form){
        Object.entries(result.form).forEach(([k,v])=>{
            const el = document.getElementById(k);
            if(el) el.value = v;
        });
    }
}

// ------------------- PDF GENERATION -------------------
function getApplicationData() {
    const fields = (window.APP_PLACEHOLDERS && window.APP_PLACEHOLDERS.length)
        ? window.APP_PLACEHOLDERS
        : DEFAULT_APP_FIELDS;
    const data = {};
    let empty = true;
    fields.forEach(f => {
        const el = document.getElementById(f);
        if(el && el.value) { data[f] = el.value; empty=false; }
    });
    if(empty){ alert("Please fill out and save the form first."); return null; }
    return data;
}

function printApplication() {
    const saved = getApplicationData();
    if(!saved) return;

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    doc.setFont("times","normal"); doc.setFontSize(14);
    doc.text("Quarry Permit Application", 105, 20, {align:"center"});

    let y = 40;
    for(const [key,value] of Object.entries(saved)){
        const label = key.replace(/([A-Z])/g,' $1').replace(/^./, str=>str.toUpperCase());
        doc.text(`${label}: ${value}`, 20, y); 
        y += key==='applicantAddress' ? 20 : 10;
    }

    doc.text("_________________________", 150, y);
    doc.text("Applicant's Signature", 150, y+10);
    doc.save("quarry-application.pdf");
}

function previewApplication() {
    const saved = getApplicationData();
    if(!saved) return;

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    doc.setFont("times","normal"); doc.setFontSize(14);
    doc.text("Quarry Permit Application", 105, 20, {align:"center"});

    let y = 40;
    for(const [key,value] of Object.entries(saved)){
        const label = key.replace(/([A-Z])/g,' $1').replace(/^./, str=>str.toUpperCase());
        doc.text(`${label}: ${value}`, 20, y);
        y += key==='applicantAddress' ? 20 : 10;
    }

    doc.text("_________________________", 150, y);
    doc.text("Applicant's Signature", 150, y+10);

    const pdfBlob = doc.output('blob');
    const pdfUrl = URL.createObjectURL(pdfBlob);
    window.open(pdfUrl, '_blank');
}

// ------------------- DOCX GENERATION -------------------
async function downloadFilledDoc() {
    const saved = getApplicationData();
    if(!saved) return;

    try {
        const res = await fetch(`${API_BASE}/api/application/generate-doc`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/octet-stream'
            },
            body: JSON.stringify(saved)
        });

        if(!res.ok){
            const text = await res.text();
            try {
                const err = JSON.parse(text);
                alert(err.message || 'Failed to generate document');
            } catch(_) {
                alert('Failed to generate document');
            }
            return;
        }

        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'mgbform8-1A-filled.docx';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch(err) {
        alert(err.message || 'Error generating document');
    }
}

// ------------------- Dynamic placeholders & form builder -------------------
async function ensurePlaceholders(force = false) {
    if (!force && Array.isArray(window.APP_PLACEHOLDERS) && window.APP_PLACEHOLDERS.length) return;
    try {
        const res = await safeFetch('/api/application/placeholders');
        let ph = Array.isArray(res.placeholders) ? res.placeholders : [];
        // Heuristic: if none or only a generic placeholder exists, fall back
        if (ph.length === 0 || (ph.length === 1 && ph[0].toLowerCase() === 'placeholdername')) {
            ph = DEFAULT_APP_FIELDS;
        }
        window.APP_PLACEHOLDERS = ph;
    } catch(e) {
        // Fallback to default fields if endpoint not available
        window.APP_PLACEHOLDERS = DEFAULT_APP_FIELDS;
    }
}

async function refreshPlaceholders(){
    window.APP_PLACEHOLDERS = [];
    await ensurePlaceholders(true);
    buildApplicationForm(window.APP_PLACEHOLDERS || DEFAULT_APP_FIELDS);
}

function buildApplicationForm(placeholders) {
    const form = document.getElementById('applicationForm');
    if (!form) return;
    // Keep the actions row; rebuild inputs above it
    const actions = form.querySelector('.actions');
    // Remove all children except actions
    Array.from(form.children).forEach(ch => { if (ch !== actions) ch.remove(); });

    const title = document.createElement('h3');
    title.textContent = 'Application Fields';
    form.insertBefore(title, actions);

    // Resume with tracking ID (paste and load)
    const resumeRow = document.createElement('div');
    resumeRow.className = 'tid-row';
    const span = document.createElement('span'); span.textContent = 'Have a Tracking ID?';
    const input = document.createElement('input');
    input.type = 'text'; input.id = 'resumeTrackId'; input.placeholder = 'paste tracking id here';
    input.style.flex = '1'; input.style.minWidth = '240px';
    const btnResume = document.createElement('button');
    btnResume.type = 'button'; btnResume.className = 'tid-copy'; btnResume.textContent = 'Resume';
    btnResume.addEventListener('click', () => resumeWithTrackingId());
    resumeRow.appendChild(span); resumeRow.appendChild(input); resumeRow.appendChild(btnResume);
    form.insertBefore(resumeRow, actions);

    // Show tracking ID for applicant convenience
    const tid = sessionStorage.getItem('tracking_id');
    if (tid) {
        const tidBox = document.createElement('div');
        tidBox.className = 'tid-row';
        const label = document.createElement('span');
        label.textContent = 'Tracking ID:';
        const codeSpan = document.createElement('code');
        codeSpan.textContent = tid;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tid-copy';
        btn.title = 'Copy tracking ID';
        btn.textContent = '📋 Copy';
        btn.addEventListener('click', () => copyTrackingId());
        tidBox.appendChild(label);
        tidBox.appendChild(codeSpan);
        tidBox.appendChild(btn);
        form.insertBefore(tidBox, actions);
    }

    if (!placeholders.length) {
        const note = document.createElement('p');
        note.textContent = 'No placeholders found in template.';
        form.insertBefore(note, actions);
        return;
    }

    placeholders.forEach(key => {
        const label = document.createElement('label');
        // Humanize the label (e.g., applicantName -> Applicant Name)
        const nice = key
            .replace(/([A-Z])/g, ' $1')
            .replace(/^./, s => s.toUpperCase())
            .replace(/_/g, ' ');
        label.textContent = nice;
        label.setAttribute('for', key);
        const input = document.createElement('input');
        input.type = 'text';
        input.id = key;
        form.insertBefore(label, actions);
        form.insertBefore(input, actions);
    });
}

// ------------------- Modal Preview -------------------
function humanizeLabel(key){
    return key.replace(/([A-Z])/g,' $1').replace(/^./, s=>s.toUpperCase()).replace(/_/g,' ');
}

function showPreview(){
    const data = getApplicationData();
    if(!data) return;

    // Attempt server PDF preview for exact layout; if it fails, fallback to HTML grid
    (async () => {
        try {
            const res = await fetch(`${API_BASE}/api/application/preview-pdf`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/pdf' },
                body: JSON.stringify(data)
            });
            if(!res.ok) throw new Error('PDF preview unavailable');
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);

            const body = document.getElementById('previewBody');
            body.innerHTML = '';
            const iframe = document.createElement('iframe');
            iframe.style.width = '100%';
            iframe.style.height = '70vh';
            iframe.src = url;
            body.appendChild(iframe);
        } catch(e) {
            // Try DOCX render fallback
            try {
                const resDocx = await fetch(`${API_BASE}/api/application/generate-doc`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
                    body: JSON.stringify(data)
                });
                if(!resDocx.ok) throw new Error('DOCX generate failed');
                const docxBlob = await resDocx.blob();
                const arrayBuf = await docxBlob.arrayBuffer();
                const body = document.getElementById('previewBody');
                body.innerHTML = '';
                const container = document.createElement('div');
                body.appendChild(container);
                if (window.docx && window.docx.renderAsync) {
                    await window.docx.renderAsync(arrayBuf, container);
                } else {
                    throw new Error('docx-preview not available');
                }
            } catch(err) {
            const placeholders = (window.APP_PLACEHOLDERS && window.APP_PLACEHOLDERS.length)
                ? window.APP_PLACEHOLDERS
                : Object.keys(data);
            const body = document.getElementById('previewBody');
            body.innerHTML = '';
            const grid = document.createElement('div');
            grid.className = 'preview-grid';
            placeholders.forEach(key => {
                const label = document.createElement('div');
                label.className = 'label';
                label.textContent = humanizeLabel(key);
                const value = document.createElement('div');
                value.className = 'value';
                value.textContent = data[key] ?? '';
                grid.appendChild(label);
                grid.appendChild(value);
            });
            body.appendChild(grid);
            }
        }

        document.getElementById('previewModal').classList.remove('hidden');
    })();
}

function closePreview(){
    document.getElementById('previewModal').classList.add('hidden');
}

// ------------------- File Uploads -------------------
function triggerUpload(){
    let input = document.getElementById('appFiles');
    if(!input){
        input = document.createElement('input');
        input.type = 'file';
        input.id = 'appFiles';
        input.multiple = true;
        input.style.display = 'none';
        input.addEventListener('change', () => uploadFiles());
        document.body.appendChild(input);
    }
    input.value = '';
    input.click();
}
async function uploadFiles(){
    const input = document.getElementById('appFiles');
    if(!input.files || input.files.length === 0){ alert('Select files first.'); return; }
    const tracking_id = sessionStorage.getItem('tracking_id') || '';
    if(!tracking_id){ alert('Tracking ID missing. Please open the Application Form again.'); return; }

    const fd = new FormData();
    fd.append('tracking_id', tracking_id);
    Array.from(input.files).forEach(f => fd.append('files[]', f));

    const res = await fetch(`${API_BASE}/api/application/upload`, {
        method: 'POST',
        body: fd
    });
    const text = await res.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch(_) {}
    if(!res.ok){ alert((data && data.message) || 'Upload failed'); return; }
    alert('Uploaded successfully');
    listFiles();
}

async function listFiles(){
    const tracking_id = encodeURIComponent(sessionStorage.getItem('tracking_id') || '');
    if(!tracking_id){ document.getElementById('fileList').innerHTML = '<li>Tracking ID not set.</li>'; return; }
    try {
        const res = await safeFetch(`/api/application/files?tracking_id=${tracking_id}`);
        const ul = document.getElementById('fileList');
        ul.innerHTML = '';
        (res.files || []).forEach(f => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = f.url || '#';
            a.target = '_blank';
            a.textContent = `${f.name} (${Math.round((f.size||0)/1024)} KB)`;
            li.appendChild(a);
            ul.appendChild(li);
        });
        if((res.files || []).length === 0){ ul.innerHTML = '<li>No files uploaded yet.</li>'; }
    } catch(err){
        console.error(err);
    }
}

// ------------------- Tracking ID -------------------
async function ensureTrackingId(){
    let tid = sessionStorage.getItem('tracking_id');
    if (tid) return tid;
    const res = await safeFetch('/api/application/start', { method:'POST' });
    tid = res.tracking_id;
    sessionStorage.setItem('tracking_id', tid);
    return tid;
}

function copyTrackingId(){
    const tid = sessionStorage.getItem('tracking_id');
    if(!tid){ alert('Tracking ID not found.'); return; }
    navigator.clipboard.writeText(tid).then(()=>{
        alert('Tracking ID copied');
    }).catch(()=>{
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = tid; document.body.appendChild(ta); ta.select();
        try{ document.execCommand('copy'); alert('Tracking ID copied'); }catch(_){ alert('Copy failed'); }
        ta.remove();
    });
}

function resumeWithTrackingId(){
    const inp = document.getElementById('resumeTrackId');
    const val = (inp?.value || '').trim();
    if(!val){ alert('Please paste a tracking ID first.'); return; }
    sessionStorage.setItem('tracking_id', val);
    // Rebuild header with new ID and load saved values
    buildApplicationForm(window.APP_PLACEHOLDERS || DEFAULT_APP_FIELDS);
    loadApplication();
}

// ------------------- Tracking warning modal -------------------
function showTrackingWarning(){
    const tid = sessionStorage.getItem('tracking_id');
    if(!tid) return;
    const code = document.getElementById('tidInModal');
    if(code) code.textContent = tid;
    const modal = document.getElementById('trackingInfoModal');
    if(modal) modal.classList.remove('hidden');
}

function closeTrackingWarning(){
    const modal = document.getElementById('trackingInfoModal');
    if(modal) modal.classList.add('hidden');
}

// ------------------- Tracking Status -------------------
async function checkStatus(){
    const input = document.getElementById('trackIdInput');
    const tracking_id = (input?.value || '').trim();
    if(!tracking_id){ alert('Enter a tracking ID'); return; }

    const res = await safeFetch(`/api/application/status?tracking_id=${encodeURIComponent(tracking_id)}`);
    let files = [];
    try {
        const fr = await safeFetch(`/api/application/files?tracking_id=${encodeURIComponent(tracking_id)}`);
        files = fr.files || [];
    } catch(_) {}

    const panel = document.getElementById('trackResult');
    panel.innerHTML = '';

    // Progress bar if available
    if (typeof res.progress === 'number'){
        const progress = document.createElement('div');
        progress.className = 'progress';
        const bar = document.createElement('div'); bar.className='progress__bar'; bar.style.width = `${res.progress}%`;
        const text = document.createElement('span'); text.className='progress__text'; text.textContent = `${res.progress}% complete`;
        progress.appendChild(bar); progress.appendChild(text); panel.appendChild(progress);
    }

    const grid = document.createElement('div');
    grid.className = 'status-grid';

    const add = (k,v) => {
        const l = document.createElement('div'); l.className='label'; l.textContent=k; grid.appendChild(l);
        const val = document.createElement('div'); val.className='value';
        if (v instanceof HTMLElement) val.appendChild(v); else val.textContent = v;
        grid.appendChild(val);
    };

    add('Tracking ID', tracking_id);
    const badge1 = document.createElement('span'); badge1.className='badge '+(res.has_form?'ok':'warn'); badge1.textContent = res.has_form? 'Saved' : 'Not saved yet';
    add('Form', badge1);
    add('Fields Filled', String(res.fields_filled || 0));
    add('Files Uploaded', String(res.files_uploaded || 0));

    panel.appendChild(grid);

    if (res.note){
        const noteDiv = document.createElement('div'); noteDiv.className='note-box';
        noteDiv.textContent = `Admin Note: ${res.note}`;
        panel.appendChild(noteDiv);
    }

    // Files list
    const ul = document.createElement('ul');
    ul.style.marginTop = '12px';
    files.forEach(f => {
        const li = document.createElement('li');
        const a = document.createElement('a'); a.href = f.url || '#'; a.target = '_blank'; a.textContent = `${f.name} (${Math.round((f.size||0)/1024)} KB)`; li.appendChild(a); ul.appendChild(li);
    });
    if(files.length===0){ const li=document.createElement('li'); li.textContent='No files uploaded yet.'; ul.appendChild(li); }
    panel.appendChild(ul);
}
