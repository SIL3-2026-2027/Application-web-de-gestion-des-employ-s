<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DOC-01 / ADM-03 : catégories du coffre-fort et qui peut les voir
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->boolean('visible_to_employee')->default(true);
            $table->boolean('visible_to_manager')->default(false);
            $table->boolean('has_expiry')->default(false); // pièce à date de validité (titre de séjour, visite médicale…)
            $table->timestamps();
        });

        // DOC-01 / DOC-02 / DOC-06 : documents de chaque employé
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_category_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->date('expires_at')->nullable();
            $table->enum('source', ['depot', 'genere'])->default('depot');
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'document_category_id']);
            $table->index('expires_at');
        });

        // DOC-04 : demandes d'attestation par les employés
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['attestation_travail', 'attestation_salaire', 'autre']);
            $table->text('comment')->nullable();
            $table->enum('status', ['en_attente', 'traitee', 'refusee'])->default('en_attente');
            $table->foreignId('employee_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('refusal_reason')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // DOC-05 : documents de l'entreprise avec accusé de lecture
        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->boolean('requires_acknowledgment')->default(true);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('company_document_acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at');

            $table->unique(['company_document_id', 'user_id'], 'company_doc_ack_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_document_acknowledgments');
        Schema::dropIfExists('company_documents');
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('document_categories');
    }
};
