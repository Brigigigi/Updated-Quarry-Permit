<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ZipArchive;

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

    public function start(Request $request)
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $dir = $this->appDir($id);
        // seed metadata
        @file_put_contents($dir.DIRECTORY_SEPARATOR.'meta.json', json_encode([
            'tracking_id' => $id,
            'created_at' => now()->toISOString(),
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

        if(!$request->hasFile('files')){
            return response()->json(['message'=>'No files uploaded'], 400);
        }

        $uploaded = [];
        foreach ((array)$request->file('files') as $file) {
            if (!$file || !$file->isValid()) continue;
            $directory = 'application_uploads/'.preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId);
            $name = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs($directory, $name);
            $uploaded[] = [
                'original' => $file->getClientOriginalName(),
                'path' => $path,
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
                'url' => url('/storage/'.str_replace('public/','',$path))
            ];
        }

        return response()->json(['message'=>'Files uploaded','files'=>$uploaded]);
    }

    // List files for a username
    public function files(Request $request)
    {
        $trackingId = $request->query('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);
        $directory = storage_path('app/application_uploads/'.preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId));
        if (!is_dir($directory)) return response()->json(['files'=>[]]);
        $files = [];
        foreach (scandir($directory) as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $directory.DIRECTORY_SEPARATOR.$f;
            if (is_file($full)) {
                $rel = 'application_uploads/'.preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId).'/'.$f;
                $files[] = [
                    'name' => $f,
                    'path' => $rel,
                    'size' => filesize($full),
                    'url' => url('/storage/'.str_replace('public/','',$rel))
                ];
            }
        }
        return response()->json(['files'=>$files]);
    }

    public function status(Request $request)
    {
        $trackingId = $request->query('tracking_id');
        if(!$trackingId) return response()->json(['message'=>'tracking_id required'], 400);
        $formPath = $this->appJsonPath($trackingId);
        $form = file_exists($formPath) ? json_decode(file_get_contents($formPath), true) : [];
        $fileDir = storage_path('app/application_uploads/'.preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId));
        $fileCount = 0;
        if (is_dir($fileDir)) {
            foreach (scandir($fileDir) as $f) { if ($f!=='.' && $f!=='..' && is_file($fileDir.DIRECTORY_SEPARATOR.$f)) $fileCount++; }
        }
        // Load admin-set progress and note
        $statusPath = storage_path('app/applications/'.preg_replace('/[^A-Za-z0-9_\-]/','_', $trackingId).'/status.json');
        $adminStatus = file_exists($statusPath) ? (json_decode(@file_get_contents($statusPath), true) ?: []) : [];
        $progress = isset($adminStatus['progress']) ? (int)$adminStatus['progress'] : 0;
        $note = isset($adminStatus['note']) ? (string)$adminStatus['note'] : '';
        return response()->json([
            'tracking_id' => $trackingId,
            'has_form' => !empty($form),
            'fields_filled' => is_array($form) ? count(array_filter($form, fn($v)=> (string)$v !== '')) : 0,
            'files_uploaded' => $fileCount,
            'progress' => $progress,
            'note' => $note,
        ]);
    }
}
