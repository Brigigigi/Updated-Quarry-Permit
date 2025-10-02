<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\PermitApplication;
use App\Models\Bond;
use App\Models\BoardAction;
use App\Models\Permit;
use Carbon\Carbon;


class AdminApplicationController extends Controller
{
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) { $this->rrmdir($path); }
            else { @unlink($path); }
        }
        @rmdir($dir);
    }
    private function getPlaceholders(): array
    {
        $templatePath = public_path('mgbform8-1A.docx');
        if (!file_exists($templatePath)) return [];
        // Prefer PhpWord when available
        if (class_exists('PhpOffice\\PhpWord\\TemplateProcessor')) {
            try {
                $tp = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
                $vars = method_exists($tp, 'getVariables') ? $tp->getVariables() : [];
                return array_values(array_unique(is_array($vars) ? $vars : []));
            } catch (\Throwable $e) { /* fallthrough */ }
        }
        // Fallback ZipArchive parse
        if (!class_exists('ZipArchive')) return [];
        $zip = new \ZipArchive();
        if ($zip->open($templatePath) !== true) return [];
        $texts = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^word/(document|header\\d+|footer\\d+).xml$#', $name)) {
                $xml = $zip->getFromIndex($i);
                if ($xml !== false) {
                    $texts .= "\n".preg_replace('/<[^>]+>/', '', $xml);
                }
            }
        }
        $zip->close();
        preg_match_all('/\\$\\{([A-Za-z0-9_\\-]+)\\}/', $texts, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    public function index(Request $request)
    {
        $q = trim((string)$request->query('q', ''));
        $placeholders = $this->getPlaceholders();
        $expected = max(1, count($placeholders));
        $appsDir = storage_path('app/applications');
        $items = [];
        if (is_dir($appsDir)) {
            foreach (scandir($appsDir) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $full = $appsDir.DIRECTORY_SEPARATOR.$dir;
                if (!is_dir($full)) continue;

                $trackingId = $dir;
                $metaPath = $full.DIRECTORY_SEPARATOR.'meta.json';
                $formPath = $full.DIRECTORY_SEPARATOR.'form.json';

                $createdAt = null;
                $isSubmitted = false;
                if (file_exists($metaPath)) {
                    $meta = json_decode(@file_get_contents($metaPath), true) ?: [];
                    $createdAt = $meta['created_at'] ?? null;
                    $isSubmitted = (bool)($meta['submitted'] ?? false) || !empty($meta['submitted_at'] ?? null);
                }

                $fieldsFilled = 0;
                $form = [];
                if (file_exists($formPath)) {
                    $form = json_decode(@file_get_contents($formPath), true) ?: [];
                    if (is_array($form)) {
                        foreach ($form as $v) { if ((string)$v !== '') $fieldsFilled++; }
                    }
                }

                $filesDir = storage_path('app/application_uploads/'.preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId));
                $filesCount = 0;
                if (is_dir($filesDir)) {
                    foreach (scandir($filesDir) as $f) {
                        if ($f==='.'||$f==='..') continue;
                        if (is_file($filesDir.DIRECTORY_SEPARATOR.$f)) $filesCount++;
                    }
                }

                // only show submitted applications
                if (!$isSubmitted) continue;

                // Try to read applicant name from saved form
                $applicantName = '';
                if (is_array($form)) {
                    $applicantName = trim((string)($form['applicantName'] ?? $form['applicant_signature_name'] ?? $form['applicantSignatureName'] ?? ''));
                }

                // progress: compute same way as the app page progress bar (checks + signoffs)
                $statusPath = $full.DIRECTORY_SEPARATOR.'status.json';
                $progress = 0;
                if (file_exists($statusPath)) {
                    $status = json_decode(@file_get_contents($statusPath), true) ?: [];
                    // Count only expected keys to avoid drift
                    $allowedChecks = ['fields_ok','files_ok','references_ok'];
                    $allowedSigners = ['chair','secretary','governor','mayor','barangay_captain'];
                    $checksCount = 0;
                    foreach ($allowedChecks as $k) { if (!empty($status['checks'][$k])) $checksCount++; }
                    $signCount = 0;
                    foreach ($allowedSigners as $r) { if (!empty($status['signoffs'][$r]['signed'])) $signCount++; }
                    $totalItems = count($allowedChecks) + count($allowedSigners); // 8
                    $progress = (int) round((($checksCount + $signCount) / max(1, $totalItems)) * 100);
                }
                // If no status yet, keep progress at 0% (admin has not started)
                if ($progress < 0) $progress = 0; if ($progress > 100) $progress = 100;

                // Format created
                $createdFmt = '';
                if (is_string($createdAt) && $createdAt !== '') {
                    try { $createdFmt = Carbon::parse($createdAt)->format('Y-m-d H:i'); } catch (\Throwable $e) { $createdFmt = str_replace(['T','Z'],' ', $createdAt); }
                }
                // Check if paid from status.json (same file we already loaded above)
                $isPaidLegacy = false;
                if ($statusPath && is_file($statusPath)) {
                    $adminStatusLegacy = json_decode(@file_get_contents($statusPath), true) ?: [];
                    $isPaidLegacy = (bool)($adminStatusLegacy['paid'] ?? false);
                }
                // map status to a badge class
                $status = $isSubmitted ? 'submitted' : 'draft';
                if ($isPaidLegacy && !in_array($status, ['approved', 'denied'])) {
                    $status = 'paid';
                }
                $statusClass = in_array($status, ['approved','paid']) ? 'ok' : (in_array($status, ['denied','draft']) ? 'warn' : 'info');

                $items[] = [
                    'tracking_id' => $trackingId,
                    'created_at' => $createdAt,
                    'created_fmt' => $createdFmt,
                    'status' => $status,
                    'status_class' => $statusClass,
                    'progress' => $progress,
                    'applicant_name' => $applicantName,
                    'source' => 'legacy',
                    'is_paid' => $isPaidLegacy,
                ];
            }
        }

        // Merge DB v2 applications if table exists
        if (Schema::hasTable('permit_application')) {
            $rows = DB::table('permit_application')
                ->select('id','tracking_id','status','created_at')
                ->orderByDesc('created_at')
                ->limit(500)
                ->get();
            foreach ($rows as $r) {
                $tid = (string) ($r->tracking_id ?? '');
                $applicantName = '';
                if ($tid !== '') {
                    $formPath = $appsDir.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9_\-]/','_', $tid).DIRECTORY_SEPARATOR.'form.json';
                    if (is_file($formPath)) {
                        $form = json_decode(@file_get_contents($formPath), true) ?: [];
                        $applicantName = trim((string)($form['applicantName'] ?? $form['applicant_signature_name'] ?? $form['applicantSignatureName'] ?? ''));
                    }
                }
                // compute progress via status.json if available
                $progress = 0;
                if ($tid !== '') {
                    $dir = $appsDir.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9_\-]/','_', $tid);
                    $statusPath = $dir.DIRECTORY_SEPARATOR.'status.json';
                    if (is_file($statusPath)) {
                        $adminStatus = json_decode(@file_get_contents($statusPath), true) ?: [];
                        $checksCount = 0; foreach ((array)($adminStatus['checks'] ?? []) as $v) { if ($v) $checksCount++; }
                        $signCount = 0; foreach ((array)($adminStatus['signoffs'] ?? []) as $row2) { if (!empty($row2['signed'])) $signCount++; }
                        $progress = (int) round((($checksCount + $signCount) / 8) * 100);
                        if ($progress < 0) $progress = 0; if ($progress > 100) $progress = 100;
                    }
                }
                // Format DB created
                $dbCreated = (string) ($r->created_at ?? '');
                $dbCreatedFmt = '';
                if ($dbCreated !== '') { try { $dbCreatedFmt = Carbon::parse($dbCreated)->format('Y-m-d H:i'); } catch (\Throwable $e) { $dbCreatedFmt = str_replace(['T','Z'],' ', $dbCreated); } }
                // latest fee badge
                $latestFee = null;
                try {
                    $latestFee = DB::table('fee_assessment')->where('application_id', $r->id)->orderByDesc('created_at')->value('total_amount');
                } catch (\Throwable $e) { $latestFee = null; }

                // Check if paid from status.json
                $isPaid = false;
                if ($tid !== '') {
                    $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $tid);
                    $statusPath = $appsDir.DIRECTORY_SEPARATOR.$safe.DIRECTORY_SEPARATOR.'status.json';
                    if (is_file($statusPath)) {
                        $adminStatus = json_decode(@file_get_contents($statusPath), true) ?: [];
                        $isPaid = (bool)($adminStatus['paid'] ?? false);
                    }
                }

                // status badge class for DB rows - prioritize 'paid' status
                $s = (string) ($r->status ?? '');
                if ($isPaid && $s !== 'approved' && $s !== 'denied') {
                    $s = 'paid';
                }
                $statusClass = in_array($s, ['approved','paid']) ? 'ok' : (in_array($s, ['denied','draft']) ? 'warn' : 'info');

                $items[] = [
                    'tracking_id' => $tid,
                    'created_at' => $dbCreated,
                    'created_fmt' => $dbCreatedFmt,
                    'status' => $s,
                    'status_class' => $statusClass,
                    'progress' => $progress,
                    'applicant_name' => $applicantName,
                    'source' => 'db',
                    'latest_fee' => $latestFee,
                    'is_paid' => $isPaid,
                ];
            }
        }

        // De-duplicate by tracking_id; prefer DB record when both exist
        $byTid = [];
        foreach ($items as $row) {
            $tid = (string)($row['tracking_id'] ?? '');
            if ($tid === '') continue;
            if (!isset($byTid[$tid])) { $byTid[$tid] = $row; continue; }
            $existing = $byTid[$tid];
            $existingSource = (string)($existing['source'] ?? '');
            $rowSource = (string)($row['source'] ?? '');
            if ($existingSource !== 'db' && $rowSource === 'db') {
                // prefer db; keep missing fields from legacy if db lacks them
                if (empty($row['applicant_name'] ?? '') && !empty($existing['applicant_name'] ?? '')) {
                    $row['applicant_name'] = $existing['applicant_name'];
                }
                if (!isset($row['progress']) && isset($existing['progress'])) {
                    $row['progress'] = $existing['progress'];
                }
                $byTid[$tid] = $row;
            }
        }
        $items = array_values($byTid);

        // Add a simple progress badge class
        foreach ($items as &$it) {
            $p = (int)($it['progress'] ?? 0);
            $it['progress_badge'] = ($p >= 75) ? 'ok' : 'warn';
        }
        unset($it);

        // Optional search across both sources
        if ($q !== '') {
            $qLower = mb_strtolower($q);
            $items = array_values(array_filter($items, function($row) use ($qLower){
                $name = mb_strtolower((string)($row['applicant_name'] ?? ''));
                $tid  = mb_strtolower((string)($row['tracking_id'] ?? ''));
                return (strpos($name, $qLower) !== false) || (strpos($tid, $qLower) !== false);
            }));
        }

        // Sort by created desc with fallback
        usort($items, function($a,$b){
            $ac = (string)($a['created_at'] ?? '');
            $bc = (string)($b['created_at'] ?? '');
            $cmp = strcmp($bc, $ac);
            if ($cmp !== 0) return $cmp;
            return strcmp((string)($a['tracking_id']??''),(string)($b['tracking_id']??''));
        });

        return view('admin.home', ['apps' => $items]);
    }

    public function show(string $trackingId)
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $dir = storage_path('app/applications/'.$safe);
        if (!is_dir($dir)) {
            abort(404);
        }

        $form = [];
        $createdAt = null;
        $metaPath = $dir.DIRECTORY_SEPARATOR.'meta.json';
        $formPath = $dir.DIRECTORY_SEPARATOR.'form.json';
        if (file_exists($metaPath)) {
            $meta = json_decode(@file_get_contents($metaPath), true) ?: [];
            $createdAt = $meta['created_at'] ?? null;
        }
        if (file_exists($formPath)) {
            $form = json_decode(@file_get_contents($formPath), true) ?: [];
        }

        $placeholders = $this->getPlaceholders();
        $expected = max(1, count($placeholders));
        $filled = 0;
        foreach ($placeholders as $k) {
            if (isset($form[$k]) && (string)$form[$k] !== '') $filled++;
        }
        if ($filled === 0 && !empty($form)) {
            // If placeholders not available, count any non-empty field
            foreach ($form as $v) { if ((string)$v !== '') $filled++; }
            $expected = max($filled, 1);
        }
        $percent = (int) round(($filled / $expected) * 100);
        $status = $percent >= 100 ? 'Complete' : ($percent >= 60 ? 'In Progress' : 'Started');

        // Applicant Files (support both public disk path and legacy path)
        $files = [];
        $seen = [];
        $dirs = [
            storage_path('app/public/application_uploads/'.$safe),
            storage_path('app/application_uploads/'.$safe), // legacy
        ];
        foreach ($dirs as $filesDir) {
            if (!is_dir($filesDir)) continue;
            foreach (scandir($filesDir) as $f) {
                if ($f==='.'||$f==='..') continue;
                $full = $filesDir.DIRECTORY_SEPARATOR.$f;
                if (is_file($full) && !isset($seen[$f])) {
                    $rel = 'application_uploads/'.$safe.'/'.$f;
                    $files[] = [
                        'name' => $f,
                        'size' => filesize($full),
                        'url' => url('/storage/'.$rel)
                    ];
                    $seen[$f] = true;
                }
            }
        }

        // Admin-provided reference files (public disk)
        $adminFiles = [];
        $adminDir = storage_path('app/public/admin_uploads/'.$safe);
        if (is_dir($adminDir)) {
            foreach (scandir($adminDir) as $f) {
                if ($f==='.'||$f==='..') continue;
                $full = $adminDir.DIRECTORY_SEPARATOR.$f;
                if (is_file($full)) {
                    $rel = 'admin_uploads/'.$safe.'/'.$f;
                    $adminFiles[] = [
                        'name' => $f,
                        'size' => filesize($full),
                        'url' => url('/storage/'.$rel)
                    ];
                }
            }
        }

        // Load admin-controlled status
        $statusPath = $dir.DIRECTORY_SEPARATOR.'status.json';
        $adminStatus = [
            'progress' => $percent,
            'checks' => [
                'fields_ok' => false,
                'files_ok' => false,
                'references_ok' => false,
            ],
            'permit_available' => false,
            'signoffs' => [
                'chair' => ['signed'=>false,'name'=>'','date'=>null],
                'secretary' => ['signed'=>false,'name'=>'','date'=>null],
                'governor' => ['signed'=>false,'name'=>'','date'=>null],
                'mayor' => ['signed'=>false,'name'=>'','date'=>null],
                'barangay_captain' => ['signed'=>false,'name'=>'','date'=>null],
            ],
        ];
        if (file_exists($statusPath)) {
            $loaded = json_decode(@file_get_contents($statusPath), true) ?: [];
            $adminStatus = array_replace_recursive($adminStatus, $loaded);
        }

        // Compute automatic progress from checks + signoffs
        $checksCount = 0;
        foreach (($adminStatus['checks'] ?? []) as $v) { if ($v) $checksCount++; }
        $signCount = 0;
        foreach (($adminStatus['signoffs'] ?? []) as $row) { if (!empty($row['signed'])) $signCount++; }
        $totalItems = 3 /*checks*/ + 5 /*signers*/;
        $autoPercent = (int) round((($checksCount + $signCount) / max(1, $totalItems)) * 100);

        return view('admin.app', [
            'tracking_id' => $trackingId,
            'created_at' => $createdAt,
            'placeholders' => $placeholders,
            'form' => $form,
            'percent' => $autoPercent,
            'status' => $status,
            'files' => $files,
            'admin_files' => $adminFiles,
            'adminStatus' => $adminStatus,
            // DB-backed summaries (if tables exist)
            'db' => $this->loadDbSummaries($trackingId),
        ]);
    }

    public function update(Request $request, string $trackingId)
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $dir = storage_path('app/applications/'.$safe);
        if (!is_dir($dir)) abort(404);

        $statusPath = $dir.DIRECTORY_SEPARATOR.'status.json';
        $current = file_exists($statusPath) ? (json_decode(@file_get_contents($statusPath), true) ?: []) : [];

        $checks = [
            'fields_ok' => (bool) $request->boolean('fields_ok'),
            'files_ok' => (bool) $request->boolean('files_ok'),
            'references_ok' => (bool) $request->boolean('references_ok'),
        ];

        $roles = ['chair','secretary','governor','mayor','barangay_captain'];
        $signoffs = [];
        foreach ($roles as $r) {
            $signed = $request->boolean("sign_$r");
            $name = trim((string) $request->input("name_$r", ''));
            $prev = $current['signoffs'][$r] ?? ['signed'=>false,'name'=>'','date'=>null];
            $date = $prev['date'] ?? null;
            if ($signed && !$prev['signed']) {
                $date = now()->toDateString();
            }
            if (!$signed) {
                // keep previous date if already signed in the past unless unchecked explicitly
                $date = $prev['date'] ?? null;
            }
            $signoffs[$r] = [
                'signed' => $signed,
                'name' => $name,
                'date' => $date,
            ];
        }

        // Auto compute progress (checks + signoffs)
        $checksCount = 0; foreach ($checks as $v) { if ($v) $checksCount++; }
        $signCount = 0; foreach ($signoffs as $row) { if (!empty($row['signed'])) $signCount++; }
        $autoPercent = (int) round((($checksCount + $signCount) / 8) * 100);

        // Replace checks/signoffs blocks completely to avoid legacy keys inflating counts
        $payload = $current;
        $payload['progress'] = $autoPercent;
        $payload['checks'] = $checks;
        $payload['signoffs'] = $signoffs;
        $payload['permit_available'] = (bool) $request->boolean('permit_available');
        $payload['note'] = (string) $request->input('note', ($current['note'] ?? ''));
        $payload['updated_at'] = now()->toISOString();

        @file_put_contents($statusPath, json_encode($payload));

        return redirect()->route('admin.app.show', ['trackingId' => $trackingId])->with('status', 'Application status updated');
    }

    private function ensurePermitAppId(string $trackingId): ?int
    {
        if (!Schema::hasTable('permit_application')) return null;
        $row = DB::table('permit_application')->where('tracking_id', $trackingId)->first();
        if ($row) return (int) $row->id;
        // create minimal row
        $id = DB::table('permit_application')->insertGetId([
            'tracking_id' => $trackingId,
            'resource_type' => 'quarry',
            'municipality' => 'Unknown',
            'province' => 'Unknown',
            'status' => 'submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    public function saveBond(Request $request, string $trackingId)
    {
        $request->validate([
            'type' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|string',
        ]);
        $appId = $this->ensurePermitAppId($trackingId);
        if (!$appId) return back()->withErrors(['bond' => 'Database not ready.'])->withInput();
        Bond::updateOrCreate(
            ['application_id' => $appId],
            [
                'type' => $request->input('type'),
                'amount' => $request->input('amount'),
                'issuer' => $request->input('issuer'),
                'policy_no' => $request->input('policy_no'),
                'issue_date' => $request->input('issue_date') ?: null,
                'expiry_date' => $request->input('expiry_date') ?: null,
                'file_url' => $request->input('file_url'),
                'status' => $request->input('status'),
            ]
        );
        // Mark DB application stage
        DB::table('permit_application')->where('id', $appId)->update(['status' => 'fees_bond', 'updated_at' => now()]);
        return back()->with('status', 'Bond saved');
    }

    public function saveBoardAction(Request $request, string $trackingId)
    {
        $request->validate([
            'resolution' => 'required|in:approve,rework,deny',
        ]);
        $appId = $this->ensurePermitAppId($trackingId);
        if (!$appId) return back()->withErrors(['board' => 'Database not ready.']);
        BoardAction::create([
            'application_id' => $appId,
            'meeting_no' => $request->input('meeting_no'),
            'resolution' => $request->input('resolution'),
            'minutes_url' => $request->input('minutes_url'),
            'decided_at' => now(),
        ]);
        $res = $request->input('resolution');
        $newStatus = ($res === 'approve') ? 'board' : (($res === 'deny') ? 'denied' : 'tech_review');
        DB::table('permit_application')->where('id', $appId)->update(['status' => $newStatus, 'updated_at' => now()]);
        return back()->with('status', 'Board action recorded');
    }

    public function grantPermit(Request $request, string $trackingId)
    {
        $request->validate([
            'permit_no' => 'required|string',
            'term_start' => 'required|date',
        ]);
        $appId = $this->ensurePermitAppId($trackingId);
        if (!$appId) return back()->withErrors(['permit' => 'Database not ready.']);
        // Preconditions: active bond and latest board approve
        $bondOk = Bond::where('application_id', $appId)->where('status','active')->exists();
        $lastBA = BoardAction::where('application_id', $appId)->orderByDesc('decided_at')->first();
        if (!$bondOk) return back()->withErrors(['permit' => 'Active bond required.']);
        if (!$lastBA || $lastBA->resolution !== 'approve') return back()->withErrors(['permit' => 'Board must approve before granting.']);

        // Ensure grantor AppUser exists from session username
        $grantorName = (string) (session('username') ?? 'Grantor');
        $grantorId = null;
        if (Schema::hasTable('app_user')) {
            $row = DB::table('app_user')->where('full_name',$grantorName)->first();
            if (!$row) {
                $grantorId = DB::table('app_user')->insertGetId([
                    'email' => null,
                    'full_name' => $grantorName,
                    'org' => null,
                    'phone' => null,
                    'role' => 'grantor',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else { $grantorId = (int)$row->id; }
        } else {
            return back()->withErrors(['permit' => 'Grantor table not ready.']);
        }

        $start = \Carbon\Carbon::parse($request->input('term_start'))->startOfDay();
        $end = $start->copy()->addYears(5);
        $permitNo = $request->input('permit_no');

        // Avoid duplicate grant
        if (Permit::where('application_id',$appId)->exists()) {
            return back()->withErrors(['permit' => 'Permit already exists for this application.']);
        }

        Permit::create([
            'application_id' => $appId,
            'permit_no' => $permitNo,
            'date_approved' => now()->toDateString(),
            'term_start' => $start->toDateString(),
            'term_end' => $end->toDateString(),
            'renewal_count' => 0,
            'total_years' => 5,
            'grantor_id' => $grantorId,
            'pdf_url' => null,
            'qr_hash' => hash('sha256', $trackingId.$permitNo.now()->toISOString()),
            'status' => 'active',
        ]);

        DB::table('permit_application')->where('id', $appId)->update(['status'=>'approved','updated_at'=>now()]);

        // Also set legacy status.json permit_available flag for tracking view
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $dir = storage_path('app/applications/'.$safe);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $statusPath = $dir.DIRECTORY_SEPARATOR.'status.json';
        $current = file_exists($statusPath) ? (json_decode(@file_get_contents($statusPath), true) ?: []) : [];
        $current['permit_available'] = true;
        $current['updated_at'] = now()->toISOString();
        @file_put_contents($statusPath, json_encode($current));

        return back()->with('status', 'Permit granted');
    }

    private function loadDbSummaries(string $trackingId): array
    {
        if (!Schema::hasTable('permit_application')) {
            return ['bond'=>null,'board'=>null,'permit'=>null,'fees'=>[],'inspections'=>[]];
        }
        $row = DB::table('permit_application')->where('tracking_id',$trackingId)->first();
        if (!$row) return ['bond'=>null,'board'=>null,'permit'=>null,'fees'=>[],'inspections'=>[]];
        $appId = (int)$row->id;
        $bond = Bond::where('application_id',$appId)->first();
        $board = BoardAction::where('application_id',$appId)->orderByDesc('decided_at')->first();
        $permit = Permit::where('application_id',$appId)->first();
        $fees = \App\Models\FeeAssessment::where('application_id',$appId)->orderByDesc('created_at')->limit(20)->get();
        $inspections = \App\Models\Inspection::where('application_id',$appId)->orderByDesc('scheduled_at')->limit(20)->get();
        $payment = null;
        try { if (Schema::hasTable('payment')) { $payment = DB::table('payment')->where('application_id',$appId)->orderByDesc('paid_at')->first(); } } catch (\Throwable $e) {}
        return compact('bond','board','permit','fees','inspections','payment');
    }

    public function export(Request $request)
    {
        // Reuse index logic partially to get combined items
        $appsDir = storage_path('app/applications');
        $items = [];
        // Legacy submitted apps only
        if (is_dir($appsDir)) {
            foreach (scandir($appsDir) as $dir) {
                if ($dir==='.'||$dir==='..') continue; $full=$appsDir.DIRECTORY_SEPARATOR.$dir; if(!is_dir($full)) continue;
                $metaPath = $full.DIRECTORY_SEPARATOR.'meta.json'; $formPath=$full.DIRECTORY_SEPARATOR.'form.json';
                $isSubmitted=false; $createdAt='';
                if (is_file($metaPath)) { $meta=json_decode(@file_get_contents($metaPath),true)?:[]; $isSubmitted=(bool)($meta['submitted']??false)||!empty($meta['submitted_at']??null); $createdAt=(string)($meta['created_at']??''); }
                if(!$isSubmitted) continue;
                $applicant=''; if(is_file($formPath)){ $form=json_decode(@file_get_contents($formPath),true)?:[]; $applicant=trim((string)($form['applicantName']??$form['applicant_signature_name']??$form['applicantSignatureName']??'')); }
                // progress from status.json
                $statusPath=$full.DIRECTORY_SEPARATOR.'status.json'; $progress=0; if(is_file($statusPath)){ $st=json_decode(@file_get_contents($statusPath),true)?:[]; $checks=0; foreach((array)($st['checks']??[]) as $v){ if($v) $checks++; } $signs=0; foreach((array)($st['signoffs']??[]) as $r){ if(!empty($r['signed'])) $signs++; } $progress=(int)round((($checks+$signs)/8)*100); if($progress<0)$progress=0; if($progress>100)$progress=100; }
                $items[] = ['tracking_id'=>$dir,'applicant'=>$applicant,'created_at'=>$createdAt,'status'=>'submitted','progress'=>$progress];
            }
        }
        // DB v2
        if (Schema::hasTable('permit_application')) {
            $rows=DB::table('permit_application')->select('tracking_id','status','created_at')->orderByDesc('created_at')->limit(500)->get();
            foreach($rows as $r){ $tid=(string)($r->tracking_id??''); $applicant=''; if($tid!==''){ $fp=$appsDir.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9_\-]/','_',$tid).DIRECTORY_SEPARATOR.'form.json'; if(is_file($fp)){ $f=json_decode(@file_get_contents($fp),true)?:[]; $applicant=trim((string)($f['applicantName']??$f['applicant_signature_name']??$f['applicantSignatureName']??'')); } }
                // progress via status.json if available
                $progress=0; if($tid!==''){ $sd=$appsDir.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9_\-]/','_',$tid); $sp=$sd.DIRECTORY_SEPARATOR.'status.json'; if(is_file($sp)){ $st=json_decode(@file_get_contents($sp),true)?:[]; $c=0; foreach((array)($st['checks']??[]) as $v){ if($v) $c++; } $s=0; foreach((array)($st['signoffs']??[]) as $x){ if(!empty($x['signed'])) $s++; } $progress=(int)round((($c+$s)/8)*100); if($progress<0)$progress=0; if($progress>100)$progress=100; } }
                $items[]=['tracking_id'=>$tid,'applicant'=>$applicant,'created_at'=>(string)($r->created_at??''),'status'=>(string)($r->status??''),'progress'=>$progress]; }
        }
        // Stream CSV
        $headers=['Content-Type'=>'text/csv','Content-Disposition'=>'attachment; filename="applications.csv"'];
        return response()->streamDownload(function() use($items){ $out=fopen('php://output','w'); fputcsv($out,['Tracking ID','Applicant','Created','Status','Progress']); foreach($items as $it){ $created = $it['created_at'] ?? ''; try { $created = \Carbon\Carbon::parse($created)->format('Y-m-d H:i'); } catch (\Throwable $e) {} fputcsv($out,[$it['tracking_id'],$it['applicant'],$created,$it['status'],$it['progress']]); } fclose($out); }, 'applications.csv', $headers);
    }

    public function saveFee(Request $request, string $trackingId)
    {
        $request->validate([
            'total_amount' => 'required|numeric|min:0',
        ]);
        $appId = $this->ensurePermitAppId($trackingId);
        if (!$appId) return back()->withErrors(['fees' => 'Database not ready.']);
        $items = $request->input('items_json');
        if (is_string($items)) { $decoded = json_decode($items, true); $items = is_array($decoded) ? $decoded : []; }
        elseif (!is_array($items)) { $items = []; }
        \App\Models\FeeAssessment::create([
            'application_id' => $appId,
            'items_json' => $items,
            'total_amount' => $request->input('total_amount'),
            'or_no' => $request->input('or_no'),
            'notes' => $request->input('notes'),
            'created_by' => null,
            'created_at' => now(),
        ]);
        DB::table('permit_application')->where('id',$appId)->update(['status'=>'fees_bond','updated_at'=>now()]);
        return back()->with('status','Fee assessment saved');
    }

    public function addInspection(Request $request, string $trackingId)
    {
        $request->validate([
            'scheduled_at' => 'required|date',
        ]);
        $appId = $this->ensurePermitAppId($trackingId);
        if (!$appId) return back()->withErrors(['inspection' => 'Database not ready.']);
        \App\Models\Inspection::create([
            'application_id' => $appId,
            'scheduled_at' => $request->input('scheduled_at'),
            'inspected_at' => $request->input('inspected_at') ?: null,
            'inspector_id' => null,
            'notes' => $request->input('notes'),
            'photos' => [],
        ]);
        DB::table('permit_application')->where('id',$appId)->update(['status'=>'inspection','updated_at'=>now()]);
        return back()->with('status','Inspection recorded');
    }

    public function destroy(Request $request, string $trackingId)
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);

        // Remove file-based storage
        $appDir = storage_path('app/applications/'.$safe);
        $uploadsDir = storage_path('app/application_uploads/'.$safe);
        $adminUploadsDir = storage_path('app/public/admin_uploads/'.$safe);
        $this->rrmdir($appDir);
        $this->rrmdir($uploadsDir);
        $this->rrmdir($adminUploadsDir);

        // Remove DB records if present
        if (Schema::hasTable('permit_application')) {
            $appRow = DB::table('permit_application')->where('tracking_id', $trackingId)->first();
            if ($appRow) {
                $appId = (int) $appRow->id;
                // Delete children first
                if (Schema::hasTable('fee_assessment')) {
                    DB::table('fee_assessment')->where('application_id', $appId)->delete();
                }
                if (Schema::hasTable('inspection')) {
                    DB::table('inspection')->where('application_id', $appId)->delete();
                }
                if (Schema::hasTable('board_action')) {
                    DB::table('board_action')->where('application_id', $appId)->delete();
                }
                if (Schema::hasTable('bond')) {
                    DB::table('bond')->where('application_id', $appId)->delete();
                }
                if (Schema::hasTable('permit')) {
                    DB::table('permit')->where('application_id', $appId)->delete();
                }
                DB::table('permit_application')->where('id', $appId)->delete();
            }
        }

        return redirect()->route('admin.home')->with('status', 'Application deleted');
    }
}
