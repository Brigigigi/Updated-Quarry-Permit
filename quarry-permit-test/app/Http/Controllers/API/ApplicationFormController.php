<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ZipArchive;
use Illuminate\Support\Facades\Storage;

class ApplicationFormController extends Controller
{
    private static $applications = []; // legacy in-memory (unused now)

    private function appDir($trackingId)
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', (string)$trackingId);
        $dir = storage_path('app/applications/'.$safe);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    private function appJsonPath($trackingId)
    {
        return $this->appDir($trackingId).DIRECTORY_SEPARATOR.'form.json';
    }

    private function appMetaPath($trackingId)
    {
        return $this->appDir($trackingId).DIRECTORY_SEPARATOR.'meta.json';
    }

    public function start(Request $request)
    {
        // Generate a short tracking code (8 upper-case chars), ensure uniqueness
        do {
            $id = strtoupper(Str::random(8));
            $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $id);
            $dir = storage_path('app/applications/'.$safe);
        } while (is_dir($dir));

        @mkdir($dir, 0775, true);
        // seed metadata
        @file_put_contents($dir.DIRECTORY_SEPARATOR.'meta.json', json_encode([
            'tracking_id' => $id,
            'created_at' => now()->toISOString(),
            'submitted' => false,
        ]));
        return response()->json(['tracking_id' => $id]);
    }

    public function save(Request $request)
    {
        $trackingId = $request->input('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);

        $data = $request->except(['tracking_id','username']);
        @file_put_contents($this->appJsonPath($trackingId), json_encode($data));
        return response()->json(['message'=>'Application saved', 'tracking_id'=>$trackingId]);
    }

    // Mark application as submitted (so admin dashboard can list it)
    public function submit(Request $request)
    {
        $trackingId = $request->input('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);

        $metaPath = $this->appMetaPath($trackingId);
        $meta = file_exists($metaPath) ? (json_decode(@file_get_contents($metaPath), true) ?: []) : [];
        $meta['tracking_id'] = $trackingId;
        $meta['submitted'] = true;
        $meta['submitted_at'] = now()->toISOString();
        @file_put_contents($metaPath, json_encode($meta));

        return response()->json(['message'=>'Application submitted', 'tracking_id'=>$trackingId]);
    }

    public function load(Request $request)
    {
        $trackingId = $request->query('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);

        $path = $this->appJsonPath($trackingId);
        $form = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        return response()->json(['form'=>$form, 'tracking_id'=>$trackingId]);
    }

    // Discover placeholders from DOCX template so the frontend can render only required fields
    public function placeholders()
    {
        $templatePath = public_path('mgbform8-1A.docx');
        if (!file_exists($templatePath)) {
            return response()->json([
                'message' => 'Template not found at public/mgbform8-1A.docx'
            ], 400);
        }

        $zip = new ZipArchive();
        if ($zip->open($templatePath) !== true) {
            return response()->json(['message' => 'Unable to open template archive'], 500);
        }

        $texts = '';
        // Collect text from document and headers/footers
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^word/(document|header\d+|footer\d+).xml$#', $name)) {
                $xml = $zip->getFromIndex($i);
                if ($xml !== false) {
                    // Remove tags but keep text; placeholders may be split across runs,
                    // so also remove common run separators.
                    $plain = preg_replace('/<[^>]+>/', '', $xml);
                    $texts .= "\n".$plain;
                }
            }
        }
        $zip->close();

        // Extract ${...} tokens
        preg_match_all('/\$\{([A-Za-z0-9_\-]+)\}/', $texts, $m);
        $unique = array_values(array_unique($m[1] ?? []));

        return response()->json(['placeholders' => $unique]);
    }

    // Generate a filled DOCX from template using PhpOffice\PhpWord\TemplateProcessor
    public function generateDoc(Request $request)
    {
        $data = $request->all();

        // Expect a DOCX template with placeholders in public/ (or adjust path as needed)
        $templatePath = public_path('mgbform8-1A.docx');
        if (!file_exists($templatePath)) {
            return response()->json([
                'message' => 'Template not found. Please convert mgbform8-1A.doc to DOCX with placeholders and save as public/mgbform8-1A.docx.'
            ], 400);
        }

        // Defer to PhpWord if installed; otherwise, hint to install
        if (!class_exists('PhpOffice\\PhpWord\\TemplateProcessor')) {
            return response()->json([
                'message' => 'PhpWord not installed. Run: composer require phpoffice/phpword'
            ], 500);
        }

        $placeholders = [
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

        $tp = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

        // Prefer variables declared in the template itself
        $templateVars = method_exists($tp, 'getVariables') ? $tp->getVariables() : [];
        if (is_array($templateVars) && !empty($templateVars)) {
            foreach ($templateVars as $key) {
                $value = isset($data[$key]) ? (string)$data[$key] : '';
                $tp->setValue($key, $value);
            }
        } else {
        foreach ($placeholders as $key) {
            $value = isset($data[$key]) ? (string)$data[$key] : '';
            // Template placeholders should be like ${key}
            $tp->setValue($key, $value);
        }
        }

        $outName = 'mgbform8-1A-filled-'.Str::uuid().'.docx';
        $outPath = storage_path('app/'.$outName);
        // Ensure storage/app exists
        if (!is_dir(storage_path('app'))) {
            @mkdir(storage_path('app'), 0775, true);
        }

        $tp->saveAs($outPath);

        return response()->download($outPath, 'mgbform8-1A-filled.docx')->deleteFileAfterSend(true);
    }

    // Improved placeholder discovery avoiding ZipArchive when PhpWord is available
    public function placeholdersSmart()
    {
        $templatePath = public_path('mgbform8-1A.docx');
        if (!file_exists($templatePath)) {
            return response()->json([
                'message' => 'Template not found at public/mgbform8-1A.docx'
            ], 400);
        }

        // Prefer PhpWord TemplateProcessor if installed
        if (class_exists('PhpOffice\\PhpWord\\TemplateProcessor')) {
            try {
                $tp = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
                $vars = method_exists($tp, 'getVariables') ? $tp->getVariables() : [];
                return response()->json(['placeholders' => array_values(array_unique($vars))]);
            } catch (\Throwable $e) {
                // will fall back below
            }
        }

        // Fallback: ZipArchive-based parsing
        if (!class_exists('ZipArchive')) {
            return response()->json([
                'message' => 'PHP ZipArchive extension not enabled. Enable ext-zip in php.ini or install PhpWord (composer require phpoffice/phpword).'
            ], 500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($templatePath) !== true) {
            return response()->json(['message' => 'Unable to open template archive'], 500);
        }

        $texts = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^word/(document|header\\d+|footer\\d+).xml$#', $name)) {
                $xml = $zip->getFromIndex($i);
                if ($xml !== false) {
                    $plain = preg_replace('/<[^>]+>/', '', $xml);
                    $texts .= "\n".$plain;
                }
            }
        }
        $zip->close();

        preg_match_all('/\\$\\{([A-Za-z0-9_\\-]+)\\}/', $texts, $m);
        $unique = array_values(array_unique($m[1] ?? []));

        return response()->json(['placeholders' => $unique]);
    }

    // Create a PDF preview by filling the DOCX and converting via LibreOffice (exact layout)
    public function previewPdf(Request $request)
    {
        $data = $request->all();

        $templatePath = public_path('mgbform8-1A.docx');
        if (!file_exists($templatePath)) {
            return response()->json(['message' => 'Template not found at public/mgbform8-1A.docx'], 400);
        }

        if (!class_exists('PhpOffice\\PhpWord\\TemplateProcessor')) {
            return response()->json(['message' => 'PhpWord not installed. Run: composer require phpoffice/phpword'], 500);
        }

        // 1) Fill DOCX to a temp file
        $tp = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
        $vars = method_exists($tp, 'getVariables') ? $tp->getVariables() : array_keys($data);
        foreach ($vars as $k) {
            $tp->setValue($k, isset($data[$k]) ? (string)$data[$k] : '');
        }

        $docxName = 'preview-'.Str::uuid().'.docx';
        $docxPath = storage_path('app/'.$docxName);
        if (!is_dir(storage_path('app'))) { @mkdir(storage_path('app'), 0775, true); }
        $tp->saveAs($docxPath);

        // 2) Convert to PDF via LibreOffice (needs soffice in PATH or default install path)
        $pdfName = pathinfo($docxName, PATHINFO_FILENAME).'.pdf';
        $pdfPath = storage_path('app/'.$pdfName);

        // Try command
        $soffice = 'soffice';
        $defaultWin = 'C:\\Program Files\\LibreOffice\\program\\soffice.exe';
        if (stripos(PHP_OS, 'WIN') === 0 && file_exists($defaultWin)) {
            $soffice = '"'.$defaultWin.'"';
        }

        $cmd = $soffice.' --headless --convert-to pdf --outdir '.escapeshellarg(dirname($pdfPath)).' '.escapeshellarg($docxPath);
        $exit = null;
        try {
            // @phpstan-ignore-next-line
            $output = [];
            $exit = null;
            exec($cmd.' 2>&1', $output, $exit);
        } catch (\Throwable $e) {
            $exit = 1;
        }

        if ($exit !== 0 || !file_exists($pdfPath)) {
            // Clean up docx
            @unlink($docxPath);
            return response()->json([
                'message' => 'LibreOffice not available to generate preview. Install LibreOffice and ensure soffice is in PATH.'
            ], 500);
        }

        // Stream inline PDF and clean up files
        return response()->file($pdfPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="application-preview.pdf"'
        ]);
    }

    // Upload supporting files for an application
    public function upload(Request $request)
    {
        $trackingId = $request->input('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);

        if(!($request->hasFile('files') || $request->hasFile('files.*') || $request->hasFile('file'))){
            return response()->json(['message'=>'No files uploaded'], 400);
        }

        $uploaded = [];
        $incoming = $request->file('files') ?? $request->file('file');
        $filesArr = is_array($incoming) ? $incoming : [$incoming];
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $disk = Storage::disk('public');
        $baseDir = 'application_uploads/'.$safe;
        if (!$disk->exists($baseDir)) {
            $disk->makeDirectory($baseDir);
        }
        foreach ($filesArr as $file) {
            if (!$file || !$file->isValid()) continue;
            $ext = $file->getClientOriginalExtension();
            $name = time().'_'.uniqid().($ext?('.'.$ext):'');
            $disk->putFileAs($baseDir, $file, $name);
            $path = 'public/'.$baseDir.'/'.$name; // virtual full path for compatibility
            $uploaded[] = [
                'original' => $file->getClientOriginalName(),
                'path' => $path,
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
                'url' => url('/storage/'.$baseDir.'/'.$name)
            ];
        }

        return response()->json(['message'=>'Files uploaded','files'=>$uploaded]);
    }

    // List files for a username
    public function files(Request $request)
    {
        $trackingId = $request->query('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $files = [];
        // Prefer public disk listing
        $diskFiles = Storage::disk('public')->files('application_uploads/'.$safe);
        foreach ($diskFiles as $relPath) {
            $name = basename($relPath);
            $full = storage_path('app/public/'.$relPath);
            $files[] = [
                'name' => $name,
                'path' => $relPath,
                'size' => is_file($full) ? filesize($full) : 0,
                'url' => url('/storage/'.$relPath)
            ];
        }
        // Legacy path fallback
        $legacyDir = storage_path('app/application_uploads/'.$safe);
        if (is_dir($legacyDir)) {
            foreach (scandir($legacyDir) as $f) {
                if ($f==='.'||$f==='..') continue;
                $full = $legacyDir.DIRECTORY_SEPARATOR.$f;
                if (is_file($full)) {
                    $rel = 'application_uploads/'.$safe.'/'.$f;
                    // Avoid duplicates if already in public disk
                    if (!collect($files)->firstWhere('name', $f)) {
                        $files[] = [
                            'name' => $f,
                            'path' => $rel,
                            'size' => filesize($full),
                            'url' => url('/storage/'.$rel)
                        ];
                    }
                }
            }
        }
        return response()->json(['files'=>$files]);
    }

    // Admin-provided reference files upload
    public function uploadAdminFiles(Request $request)
    {
        $trackingId = $request->input('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);
        if(!($request->hasFile('files') || $request->hasFile('files.*') || $request->hasFile('file'))){
            return response()->json(['message'=>'No files uploaded'], 400);
        }

        $uploaded = [];
        $incoming = $request->file('files') ?? $request->file('file');
        $filesArr = is_array($incoming) ? $incoming : [$incoming];
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $disk = Storage::disk('public');
        $baseDir = 'admin_uploads/'.$safe;
        if (!$disk->exists($baseDir)) { $disk->makeDirectory($baseDir); }
        foreach ($filesArr as $file) {
            if (!$file || !$file->isValid()) continue;
            $ext = $file->getClientOriginalExtension();
            $name = time().'_'.uniqid().($ext?('.'.$ext):'');
            $disk->putFileAs($baseDir, $file, $name);
            $uploaded[] = [
                'original' => $file->getClientOriginalName(),
                'path' => $baseDir.'/'.$name,
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
                'url' => url('/storage/'.$baseDir.'/'.$name)
            ];
        }
        return response()->json(['message'=>'Files uploaded','files'=>$uploaded]);
    }

    // List admin-provided reference files
    public function adminFiles(Request $request)
    {
        $trackingId = $request->query('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $files = [];
        $diskFiles = Storage::disk('public')->files('admin_uploads/'.$safe);
        foreach ($diskFiles as $relPath) {
            $name = basename($relPath);
            $full = storage_path('app/public/'.$relPath);
            $files[] = [
                'name' => $name,
                'path' => $relPath,
                'size' => is_file($full) ? filesize($full) : 0,
                // Prefer API download route to avoid needing the public storage symlink
                'url' => url('/api/application/admin-files/download?tracking_id='.$safe.'&name='.rawurlencode($name))
            ];
        }
        return response()->json(['files'=>$files]);
    }

    // Stream admin-provided file securely
    public function downloadAdminFile(Request $request)
    {
        $trackingId = (string) $request->query('tracking_id');
        $name = (string) $request->query('name');
        if(!$trackingId || !$name) return response()->json(['message'=>'tracking_id and name are required'], 400);
        // deny path traversal (avoid regex to prevent delimiter issues)
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return response()->json(['message'=>'Invalid file name'], 400);
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $rel = 'admin_uploads/'.$safe.'/'.$name;
        $full = storage_path('app/public/'.$rel);
        if (!is_file($full)) return response()->json(['message'=>'File not found'], 404);
        // Sanitize download name for headers to avoid Content-Disposition regex issues
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';
        return response()->download($full, $safeName);
    }

    public function status(Request $request)
    {
        $trackingId = $request->query('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);
        $formPath = $this->appJsonPath($trackingId);
        $form = file_exists($formPath) ? json_decode(file_get_contents($formPath), true) : [];
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        // Count files from both public disk and legacy folder
        $fileCount = 0;
        $publicFiles = \Illuminate\Support\Facades\Storage::disk('public')->files('application_uploads/'.$safe);
        $fileCount += is_array($publicFiles) ? count($publicFiles) : 0;
        $legacyDir = storage_path('app/application_uploads/'.$safe);
        if (is_dir($legacyDir)) {
            foreach (scandir($legacyDir) as $f) { if ($f!=='.' && $f!=='..' && is_file($legacyDir.DIRECTORY_SEPARATOR.$f)) $fileCount++; }
        }
        // Load admin-set progress and note
        $statusPath = storage_path('app/applications/'.$safe.'/status.json');
        $adminStatus = file_exists($statusPath) ? (json_decode(@file_get_contents($statusPath), true) ?: []) : [];
        $progress = isset($adminStatus['progress']) ? (int)$adminStatus['progress'] : 0;
        $note = isset($adminStatus['note']) ? (string)$adminStatus['note'] : '';

        // Final permit availability is admin-controlled flag in status.json
        $permitAvailable = (bool)($adminStatus['permit_available'] ?? false);
        return response()->json([
            'tracking_id' => $trackingId,
            'has_form' => !empty($form),
            'fields_filled' => is_array($form) ? count(array_filter($form, fn($v)=> (string)$v !== '')) : 0,
            'files_uploaded' => $fileCount,
            'progress' => $progress,
            'note' => $note,
            'permit_available' => $permitAvailable,
        ]);
    }

    // Admin uploads final permit for applicant to download
    public function uploadPermit(Request $request)
    {
        $trackingId = (string) $request->input('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);
        if(!$request->hasFile('permit')) return response()->json(['message'=>'permit file required (field name: permit)'], 400);

        $file = $request->file('permit');
        if(!$file || !$file->isValid()) return response()->json(['message'=>'invalid file'], 400);
        // Enforce .docx only
        $ext = strtolower((string)$file->getClientOriginalExtension());
        if ($ext !== 'docx') return response()->json(['message'=>'Only .docx files are allowed for final permit'], 422);

        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $disk = Storage::disk('public');
        $dir = 'permits/'.$safe;
        if(!$disk->exists($dir)) $disk->makeDirectory($dir);
        // Remove previous permit(s) to keep single canonical file
        foreach ($disk->files($dir) as $old) { $disk->delete($old); }
        $name = 'permit.docx';
        $disk->putFileAs($dir, $file, $name);
        $url = url('/storage/'.$dir.'/'.$name);

        // Auto-set permit_available flag in status.json so tracking reflects availability
        $appsDir = storage_path('app/applications/'.$safe);
        if (!is_dir($appsDir)) { @mkdir($appsDir, 0775, true); }
        $statusPath = $appsDir.DIRECTORY_SEPARATOR.'status.json';
        $current = file_exists($statusPath) ? (json_decode(@file_get_contents($statusPath), true) ?: []) : [];
        $current['permit_available'] = true;
        $current['updated_at'] = now()->toISOString();
        @file_put_contents($statusPath, json_encode($current));

        return response()->json(['message'=>'Permit uploaded','url'=>$url, 'permit_available'=>true]);
    }

    // Applicant downloads final permit
    public function downloadPermit(Request $request)
    {
        $trackingId = (string) $request->query('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
        $disk = Storage::disk('public');
        $dir = 'permits/'.$safe;
        $files = $disk->files($dir);
        if(empty($files)) return response()->json(['message'=>'Permit not available yet'], 404);
        $path = $files[0];
        $full = storage_path('app/public/'.$path);
        $downloadName = 'permit-'.$safe.'.docx';
        if(!is_file($full)) return response()->json(['message'=>'Permit not available yet'], 404);
        return response()->download($full, $downloadName);
    }
}
