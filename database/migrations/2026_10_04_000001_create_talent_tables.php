<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'role')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('role')->default('applicant')->index());
        }
        // MySQL DDL is not transactional. Resume tables completed before a failure.
        $create = function (string $name, Closure $definition): void {
            if (!Schema::hasTable($name)) {
                Schema::create($name, $definition);
            }
        };
        $create('applications', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->string('applicant_type')->index();
            $t->string('name');
            $t->string('email')->index();
            $t->string('phone')->nullable();
            $t->string('country')->nullable()->index();
            $t->string('timezone')->nullable();
            $t->string('status')->default('submitted')->index();
            $t->string('kyc_status')->default('not_started')->index();
            $t->string('nda_status')->default('not_issued')->index();
            $t->json('profile')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
        });
        $create('application_services', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->string('service')->index();
            $t->unsignedSmallInteger('experience_years')->nullable();
            $t->json('details')->nullable();
            $t->unique(['application_id', 'service']);
            $t->timestamps();
        });
        $create('application_languages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->string('language')->index();
            $t->string('proficiency');
            $t->timestamps();
        });
        $create('language_pairs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_service_id')->constrained()->cascadeOnDelete();
            $t->string('source_language')->index();
            $t->string('target_language')->index();
            $t->boolean('both_ways')->default(false);
            $t->unsignedInteger('preference')->default(1);
        });
        $create('service_domains', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_service_id')->constrained()->cascadeOnDelete();
            $t->string('domain')->index();
            $t->unique(['application_service_id', 'domain']);
        });
        $create('service_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_service_id')->constrained()->cascadeOnDelete();
            $t->string('activity');
            $t->string('unit');
            $t->decimal('amount', 12, 4)->nullable();
            $t->char('currency', 3)->default('USD');
            $t->unique(['application_service_id', 'activity', 'unit', 'currency'], 'service_rates_service_activity_unit_currency_unique');
        });
        if (!Schema::hasIndex('service_rates', 'service_rates_service_activity_unit_currency_unique')) {
            Schema::table('service_rates', fn (Blueprint $t) => $t->unique(
                ['application_service_id', 'activity', 'unit', 'currency'],
                'service_rates_service_activity_unit_currency_unique'
            ));
        }
        foreach (['education_entries', 'professional_links', 'references', 'service_tools'] as $name) {
            $create($name, function (Blueprint $t) {
                $t->id();
                $t->foreignId('application_id')->constrained()->cascadeOnDelete();
                $t->json('details');
                $t->timestamps();
            });
        }
        $create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->string('category');
            $t->string('original_name');
            $t->string('disk')->default('local');
            $t->string('path');
            $t->string('mime');
            $t->unsignedBigInteger('bytes');
            $t->char('sha256', 64)->index();
            $t->unsignedInteger('version')->default(1);
            $t->string('status')->default('pending')->index();
            $t->foreignId('supersedes_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->timestamps();
        });
        $create('document_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->string('status')->default('pending');
            $t->string('provider')->nullable();
            $t->string('model_version')->nullable();
            $t->string('outcome')->nullable();
            $t->json('findings')->nullable();
            $t->json('coverage')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        $create('agreements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->string('template_version');
            $t->string('status')->default('issued');
            $t->string('signed_path')->nullable();
            $t->timestamp('issued_at');
            $t->timestamp('signed_at')->nullable();
            $t->json('signing_evidence')->nullable();
            $t->timestamps();
        });
        $create('review_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->string('from_status');
            $t->string('to_status');
            $t->text('note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['review_events', 'agreements', 'document_assessments', 'documents', 'service_tools', 'references', 'professional_links', 'education_entries', 'service_rates', 'service_domains', 'language_pairs', 'application_languages', 'application_services', 'applications'] as $name) {
            Schema::dropIfExists($name);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
