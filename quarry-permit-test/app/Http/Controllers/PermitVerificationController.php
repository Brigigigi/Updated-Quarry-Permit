<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PermitVerificationController extends Controller
{
    public function show(Request $request)
    {
        $tracking = trim((string) $request->query('tracking', ''));
        $signature = trim((string) $request->query('sig', ''));

        $expectedSignature = $tracking !== '' ? hash_hmac('sha256', $tracking, config('app.key')) : null;
        $validSignature = $tracking !== '' && $signature !== '' && $expectedSignature && hash_equals($expectedSignature, $signature);

        $statusData = [];
        $formData = [];
        $metaData = [];
        $permitApplication = null;
        $permit = null;
        $latestPayment = null;
        $paid = false;
        $granted = false;

        if ($validSignature) {
            $safeTracking = preg_replace('/[^A-Za-z0-9_\-]/', '_', $tracking);
            $applicationDir = storage_path('app/applications/'.$safeTracking);

            if (is_dir($applicationDir)) {
                $statusPath = $applicationDir.DIRECTORY_SEPARATOR.'status.json';
                if (is_file($statusPath)) {
                    $statusData = json_decode(@file_get_contents($statusPath), true) ?: [];
                }

                $formPath = $applicationDir.DIRECTORY_SEPARATOR.'form.json';
                if (is_file($formPath)) {
                    $formData = json_decode(@file_get_contents($formPath), true) ?: [];
                }

                $metaPath = $applicationDir.DIRECTORY_SEPARATOR.'meta.json';
                if (is_file($metaPath)) {
                    $metaData = json_decode(@file_get_contents($metaPath), true) ?: [];
                }
            }

            $paid = (bool) Arr::get($statusData, 'paid', false);
            $granted = (bool) Arr::get($statusData, 'permit_available', false);

            if (Schema::hasTable('permit_application')) {
                $permitApplication = DB::table('permit_application')->where('tracking_id', $tracking)->first();
            }

            if ($permitApplication && Schema::hasTable('payment')) {
                $latestPayment = DB::table('payment')
                    ->where('application_id', $permitApplication->id)
                    ->orderByDesc('paid_at')
                    ->first();
                if ($latestPayment) {
                    $paid = true;
                }
            }

            if ($permitApplication && Schema::hasTable('permit')) {
                $permit = DB::table('permit')->where('application_id', $permitApplication->id)->first();
            }

            if ($permit) {
                $permitStatus = strtolower((string) ($permit->status ?? ''));
                if (in_array($permitStatus, ['active', 'suspended', 'expired'], true)) {
                    $granted = true;
                }
            }
        }

        $allowDetails = $validSignature && $paid && $granted;

        $details = [];
        if ($allowDetails) {
            $details['Tracking ID'] = $tracking;
            if ($permit && !empty($permit->permit_no)) {
                $details['Permit No'] = $permit->permit_no;
            }
            if (!empty($formData['applicantName'])) {
                $details['Applicant'] = $formData['applicantName'];
            } elseif (!empty($formData['applicantSignatureName'])) {
                $details['Applicant'] = $formData['applicantSignatureName'];
            }
            if (!empty($formData['municipality']) || !empty($formData['province'])) {
                $municipality = $formData['municipality'] ?? '';
                $province = $formData['province'] ?? '';
                $details['Location'] = trim($municipality.' '.$province);
            }
            if ($permit && !empty($permit->term_start) && !empty($permit->term_end)) {
                $details['Term'] = $permit->term_start.' to '.$permit->term_end;
            }
            if ($latestPayment) {
                $details['Payment Reference'] = $latestPayment->reference ?? '';
                if (!empty($latestPayment->paid_at)) {
                    $details['Paid At'] = (string) $latestPayment->paid_at;
                }
            } elseif (!empty(Arr::get($statusData, 'payment.reference'))) {
                $details['Payment Reference'] = Arr::get($statusData, 'payment.reference');
                if (Arr::get($statusData, 'payment.paid_at')) {
                    $details['Paid At'] = Arr::get($statusData, 'payment.paid_at');
                }
            }
        }

        $message = null;
        if ($tracking === '') {
            $message = 'Tracking ID is missing.';
        } elseif (!$validSignature) {
            $message = 'Invalid or tampered permit code.';
        } elseif (!$paid || !$granted) {
            $message = 'Permit is not yet paid and granted.';
        } else {
            $message = 'Permit verified successfully.';
        }

        return view('permit.verify', [
            'tracking' => $tracking,
            'validSignature' => $validSignature,
            'paid' => $paid,
            'granted' => $granted,
            'statusData' => $statusData,
            'formData' => $formData,
            'metaData' => $metaData,
            'application' => $permitApplication,
            'permit' => $permit,
            'latestPayment' => $latestPayment,
            'allowDetails' => $allowDetails,
            'details' => array_filter($details, fn ($value) => $value !== null && $value !== ''),
            'message' => $message,
            'verifiedAt' => now(),
        ]);
    }
}
