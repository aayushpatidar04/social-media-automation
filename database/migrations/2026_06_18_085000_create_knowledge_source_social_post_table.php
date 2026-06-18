<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_source_social_post', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('knowledge_source_id')
                ->constrained('knowledge_sources')
                ->cascadeOnDelete();

            $table->foreignId('social_post_id')
                ->constrained('social_posts')
                ->cascadeOnDelete();

            $table->enum('usage_type', ['primary', 'supporting', 'faq', 'policy'])
                ->default('supporting');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['knowledge_source_id', 'social_post_id'], 'ks_post_unique');
        });

        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->enum('scope', ['global', 'post_specific'])
                ->default('global')
                ->after('type');

            $table->boolean('is_active')
                ->default(true)
                ->after('is_indexed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_source_social_post');
    }
};
