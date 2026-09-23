<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rules an organization runs on: who reviews what and in which order, who
 * may delegate to whom, how the OPCR is created and published, and the rating
 * instrument itself.
 *
 * These lived in PHP constants and changed every time the college described
 * their process a little differently. Held as data, a change is an edit in
 * Setup -> Workflow instead of a deployment.
 *
 * Anything never touched falls back to config/pms.php, so an organization is
 * usable from the moment it is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('key', 60);
            $table->json('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_by_name')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_settings');
    }
};
