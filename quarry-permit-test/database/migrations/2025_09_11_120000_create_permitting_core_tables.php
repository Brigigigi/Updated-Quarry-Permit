<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Domain applicant/staff table (separate from auth users)
        if (!Schema::hasTable('app_user')) {
        Schema::create('app_user', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('full_name');
            $table->string('org')->nullable();
            $table->string('phone')->nullable();
            $table->string('role'); // applicant, intake, evaluator, treasurer, inspector, secretariat, board, grantor, records, auditor, admin
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        }

        if (!Schema::hasTable('permit_application')) {
        Schema::create('permit_application', function (Blueprint $table) {
            $table->id();
            $table->string('app_no')->unique()->nullable();
            $table->foreignId('applicant_id')->nullable()->constrained('app_user');
            $table->string('tracking_id')->unique()->nullable();
            $table->string('resource_type');
            $table->string('sitio')->nullable();
            $table->string('barangay')->nullable();
            $table->string('municipality');
            $table->string('province');
            $table->string('island')->nullable();
            $table->string('north_boundary')->nullable();
            $table->string('east_boundary')->nullable();
            $table->string('south_boundary')->nullable();
            $table->string('west_boundary')->nullable();
            $table->decimal('area_hectares', 12, 4)->default(0);
            $table->string('survey_plan_no')->nullable();
            $table->string('technical_desc_url')->nullable();
            $table->string('ecc_url')->nullable();
            $table->string('epep_url')->nullable();
            $table->string('status'); // draft, submitted, intake_review, tech_review, fees_bond, inspection, board, approved, denied, withdrawn
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
        }

        if (!Schema::hasTable('bond')) {
        Schema::create('bond', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('permit_application');
            $table->string('type');
            $table->decimal('amount', 14, 2);
            $table->string('issuer')->nullable();
            $table->string('policy_no')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('file_url')->nullable();
            $table->string('status'); // pending, active, expired, released
        });
        }

        if (!Schema::hasTable('fee_assessment')) {
        Schema::create('fee_assessment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('permit_application');
            $table->json('items_json');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('or_no')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('app_user');
            $table->timestamp('created_at')->useCurrent();
        });
        }

        if (!Schema::hasTable('inspection')) {
        Schema::create('inspection', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('permit_application');
            $table->timestamp('scheduled_at');
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspector_id')->nullable()->constrained('app_user');
            $table->text('notes')->nullable();
            $table->json('photos')->nullable();
        });
        }

        if (!Schema::hasTable('board_action')) {
        Schema::create('board_action', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('permit_application');
            $table->string('meeting_no')->nullable();
            $table->string('resolution'); // approve, rework, deny
            $table->string('minutes_url')->nullable();
            $table->timestamp('decided_at')->useCurrent();
        });
        }

        if (!Schema::hasTable('permit')) {
        Schema::create('permit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained('permit_application');
            $table->string('permit_no')->unique();
            $table->date('date_approved');
            $table->date('term_start');
            $table->date('term_end');
            $table->integer('renewal_count')->default(0);
            $table->integer('total_years')->default(5);
            $table->foreignId('grantor_id')->constrained('app_user');
            $table->string('pdf_url')->nullable();
            $table->string('qr_hash')->unique()->nullable();
            $table->string('status'); // active, suspended, revoked, expired
        });
        }

        if (!Schema::hasTable('notarial_ack')) {
        Schema::create('notarial_ack', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permit_id')->unique()->constrained('permit');
            $table->string('notary_name')->nullable();
            $table->string('ptr_no')->nullable();
            $table->string('doc_no')->nullable();
            $table->string('page_no')->nullable();
            $table->string('book_no')->nullable();
            $table->string('series')->nullable();
            $table->date('date_executed')->nullable();
            $table->string('scan_url')->nullable();
        });
        }

        if (!Schema::hasTable('quarterly_report')) {
        Schema::create('quarterly_report', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permit_id')->constrained('permit');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('filed_at');
            $table->decimal('qty_extracted', 16, 3)->default(0);
            $table->decimal('fees_paid', 14, 2)->default(0);
            $table->json('recipients_json')->nullable();
            $table->boolean('cc_sent')->default(false);
            $table->unique(['permit_id','period_start','period_end']);
        });
        }

        if (!Schema::hasTable('violation_case')) {
        Schema::create('violation_case', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permit_id')->constrained('permit');
            $table->string('type');
            $table->json('evidence')->nullable();
            $table->string('action_taken')->nullable(); // show_cause, suspend, revoke, close
            $table->string('status'); // open, resolved, closed
            $table->timestamp('created_at')->useCurrent();
        });
        }

        if (!Schema::hasTable('audit_log')) {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('app_user');
            $table->string('action');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamp('at')->useCurrent();
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('violation_case');
        Schema::dropIfExists('quarterly_report');
        Schema::dropIfExists('notarial_ack');
        Schema::dropIfExists('permit');
        Schema::dropIfExists('board_action');
        Schema::dropIfExists('inspection');
        Schema::dropIfExists('fee_assessment');
        Schema::dropIfExists('bond');
        Schema::dropIfExists('permit_application');
        Schema::dropIfExists('app_user');
    }
};
