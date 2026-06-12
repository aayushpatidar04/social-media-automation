<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add missing columns to social_comments table
        Schema::table('social_comments', function (Blueprint $table) {
            // Add intent confidence score column
            if (!Schema::hasColumn('social_comments', 'intent_confidence')) {
                $table->integer('intent_confidence')->default(0)->after('intent')->comment('Confidence score for intent classification (0-100)');
            }

            // Add AI analysis tracking columns
            if (!Schema::hasColumn('social_comments', 'ai_analysis_failed')) {
                $table->boolean('ai_analysis_failed')->default(false)->after('is_flagged')->comment('Whether AI analysis failed for this comment');
            }

            if (!Schema::hasColumn('social_comments', 'ai_error_message')) {
                $table->string('ai_error_message')->nullable()->after('ai_analysis_failed')->comment('Error message if AI analysis failed');
            }

            if (!Schema::hasColumn('social_comments', 'ai_analysis_completed_at')) {
                $table->dateTime('ai_analysis_completed_at')->nullable()->after('ai_error_message')->comment('When AI analysis was completed');
            }

            if (!Schema::hasColumn('social_comments', 'ai_response_text')) {
                $table->text('ai_response_text')->nullable()->after('ai_analysis_completed_at')->comment('Generated AI response text (before approval)');
            }
        });

        // Add missing columns to ai_conversations table
        Schema::table('ai_conversations', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_conversations', 'model_used')) {
                $table->string('model_used')->default('ollama_gemma2')->after('sent_at')->comment('AI model used for generating the response');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_comments', function (Blueprint $table) {
            $table->dropColumnIfExists([
                'intent_confidence',
                'ai_analysis_failed',
                'ai_error_message',
                'ai_analysis_completed_at',
                'ai_response_text',
            ]);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumnIfExists('model_used');
        });
    }
};
