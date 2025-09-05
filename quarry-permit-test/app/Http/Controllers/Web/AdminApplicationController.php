<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminApplicationController extends Controller
{
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
                if (file_exists($metaPath)) {
                    $meta = json_decode(@file_get_contents($metaPath), true) ?: [];
                    $createdAt = $meta['created_at'] ?? null;
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

                $items[] = [
                    'tracking_id' => $trackingId,
                    'created_at' => $createdAt,
                    'fields_filled' => $fieldsFilled,
                    'files_uploaded' => $filesCount,
                ];
            }
        }

        // Simple sort by created_at desc if available
        usort($items, function($a,$b){ return strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')); });

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

        // Files
        $filesDir = storage_path('app/application_uploads/'.$safe);
        $files = [];
        if (is_dir($filesDir)) {
            foreach (scandir($filesDir) as $f) {
                if ($f==='.'||$f==='..') continue;
                $full = $filesDir.DIRECTORY_SEPARATOR.$f;
                if (is_file($full)) {
                    $rel = 'application_uploads/'.$safe.'/'.$f;
                    $files[] = [
                        'name' => $f,
                        'size' => filesize($full),
                        'url' => url('/storage/'.str_replace('public/','',$rel))
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
            'adminStatus' => $adminStatus,
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

        $payload = array_replace_recursive($current, [
            'progress' => $autoPercent,
            'checks' => $checks,
            'signoffs' => $signoffs,
            'note' => (string) $request->input('note', ($current['note'] ?? '')),
            'updated_at' => now()->toISOString(),
        ]);

        @file_put_contents($statusPath, json_encode($payload));

        return redirect()->route('admin.app.show', ['trackingId' => $trackingId])->with('status', 'Application status updated');
    }
}
