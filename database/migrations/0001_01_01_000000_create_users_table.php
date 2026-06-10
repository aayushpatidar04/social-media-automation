<?php

// database/migrations/2024_01_01_000000_create_initial_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Organizations Table
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('website')->nullable();
            $table->enum('plan', ['free', 'starter', 'professional', 'enterprise'])->default('free');
            $table->dateTime('plan_expires_at')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Users Table
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('avatar_url')->nullable();
            $table->enum('role', ['admin', 'manager', 'team_member', 'viewer'])->default('team_member');
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        // Social Accounts Table
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('platform', ['facebook', 'instagram', 'youtube', 'twitter', 'linkedin'])->index();
            $table->string('platform_account_id')->unique();
            $table->string('platform_account_name');
            $table->string('platform_account_handle')->nullable();
            $table->string('profile_picture_url')->nullable();
            $table->string('access_token')->encrypted();
            $table->string('refresh_token')->nullable()->encrypted();
            $table->dateTime('token_expires_at')->nullable();
            $table->json('platform_data')->nullable(); // Store platform-specific data
            $table->enum('status', ['connected', 'disconnected', 'expired', 'error'])->default('connected');
            $table->string('error_message')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Social Posts Table
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->string('platform_post_id')->unique();
            $table->text('content');
            $table->string('post_url')->nullable();
            $table->string('posted_by')->nullable();
            $table->integer('likes_count')->default(0);
            $table->integer('shares_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->json('media_urls')->nullable(); // URLs of images/videos
            $table->dateTime('posted_at');
            $table->dateTime('fetched_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Social Comments Table
        Schema::create('social_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->string('platform_comment_id')->unique();
            $table->string('platform_author_id');
            $table->string('author_name');
            $table->string('author_avatar_url')->nullable();
            $table->string('author_profile_url')->nullable();
            $table->text('content');
            $table->enum('sentiment', ['positive', 'neutral', 'negative', 'pending'])->default('pending')->index();
            $table->integer('sentiment_score')->default(0); // 0-100
            $table->enum('intent', ['sales', 'support', 'complaint', 'question', 'general', 'lead', 'pending'])->default('pending')->index();
            $table->integer('lead_score')->default(0); // 0-100
            $table->boolean('is_lead')->default(false)->index();
            $table->enum('status', ['new', 'reviewed', 'replied', 'dismissed'])->default('new')->index();
            $table->boolean('is_flagged')->default(false);
            $table->string('flag_reason')->nullable();
            $table->dateTime('commented_at');
            $table->timestamps();
            $table->softDeletes();
        });

        // AI Conversations Table
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_comment_id')->constrained('social_comments')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->text('original_comment');
            $table->json('ai_analysis')->nullable(); // Store full AI analysis
            $table->text('ai_response')->nullable();
            $table->integer('confidence_score')->default(0); // 0-100
            $table->boolean('requires_human_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->text('human_override_response')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users');
            $table->dateTime('reviewed_at')->nullable();
            $table->enum('response_status', ['pending', 'approved', 'rejected', 'auto_sent', 'manually_sent'])->default('pending')->index();
            $table->dateTime('sent_at')->nullable();
            $table->string('sent_platform_id')->nullable();
            $table->enum('send_status', ['pending', 'success', 'failed'])->default('pending');
            $table->string('send_error_message')->nullable();
            $table->timestamps();
        });

        // Knowledge Sources Table
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['pdf', 'docx', 'faq', 'script', 'policy', 'template', 'brochure'])->index();
            $table->string('file_path');
            $table->string('original_filename');
            $table->integer('file_size'); // in bytes
            $table->integer('total_chunks')->default(0);
            $table->text('raw_text')->nullable(); // Store extracted text
            $table->json('metadata')->nullable();
            $table->boolean('is_indexed')->default(false);
            $table->dateTime('indexed_at')->nullable();
            $table->integer('embedding_model_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        // Knowledge Chunks Table (for RAG)
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_source_id')->constrained('knowledge_sources')->cascadeOnDelete();
            $table->integer('chunk_number');
            $table->text('content');
            $table->integer('token_count')->default(0);
            $table->json('embedding')->nullable(); // Vector embedding
            $table->string('embedding_model')->default('text-embedding-3-small');
            $table->timestamps();
        });

        // Leads Table
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('social_comment_id')->constrained('social_comments')->cascadeOnDelete();
            $table->string('platform_author_id');
            $table->string('author_name');
            $table->string('author_profile_url')->nullable();
            $table->text('initial_message');
            $table->enum('lead_type', ['sales', 'support', 'partnership', 'feedback', 'other'])->default('sales');
            $table->integer('lead_score')->default(0); // 0-100
            $table->enum('lead_status', ['new', 'contacted', 'qualified', 'converted', 'lost'])->default('new')->index();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('company_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users');
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('last_contacted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Activity Logs Table
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        // Notifications Table
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->json('data')->nullable();
            $table->string('action_url')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });

        // Analytics Cache Table (for real-time stats)
        Schema::create('analytics_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('metric_type'); // comments_count, sentiment_distribution, etc.
            $table->json('data');
            $table->dateTime('last_updated_at');
            $table->timestamps();
            $table->unique(['organization_id', 'metric_type']);
        });

        // Queue Failures (for monitoring)
        Schema::create('queue_failures', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->default('default');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_failures');
        Schema::dropIfExists('analytics_cache');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_sources');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('social_comments');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');
    }
};