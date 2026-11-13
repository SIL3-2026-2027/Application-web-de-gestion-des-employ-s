<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CTR-01 à CTR-06 : contrats et avenants (un avenant est une ligne rattachée à son contrat via parent_id)
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('contracts')->cascadeOnDelete();
            $table->enum('kind', ['contrat', 'avenant'])->default('contrat');
            $table->string('reference', 30)->unique();

            // Contrat : type et dates. Avenant : start_date = date d'effet, end_date = nouvelle date de fin éventuelle
            $table->enum('type', ['CDI', 'CDD', 'stage', 'alternance', 'interim', 'freelance'])->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('trial_end_date')->nullable();

            // Conditions (sur un avenant : seules les valeurs modifiées sont renseignées)
            $table->decimal('weekly_hours', 4, 1)->nullable();
            $table->decimal('base_salary', 10, 2)->nullable(); // brut annuel
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('collective_agreement')->nullable();
            $table->text('description')->nullable();

            // Avenant appliqué au dossier employé (poste / département) à sa date d'effet
            $table->timestamp('applied_at')->nullable();

            // CTR-06 : fin de contrat
            $table->date('ended_at')->nullable();
            $table->enum('end_reason', ['demission', 'licenciement', 'fin_cdd', 'rupture_conventionnelle', 'retraite', 'fin_periode_essai', 'fin_stage', 'autre'])->nullable();
            $table->text('end_notes')->nullable();

            $table->string('document_path')->nullable(); // exemplaire signé numérisé
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'kind', 'start_date']);
            $table->index('end_date');
            $table->index('trial_end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
