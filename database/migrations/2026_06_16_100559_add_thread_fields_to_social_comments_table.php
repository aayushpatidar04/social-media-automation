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
        Schema::table('social_comments', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('id');
            $table->unsignedBigInteger('root_id')->nullable()->after('parent_id');

            $table->string('platform_parent_id')->nullable()->after('platform_comment_id');
            $table->string('platform_root_id')->nullable()->after('platform_parent_id');

            $table->enum('direction', ['inbound', 'outbound'])->default('inbound')->after('content');
            $table->enum('sender_type', ['customer', 'page', 'ai', 'admin'])->default('customer')->after('direction');

            $table->boolean('is_own_comment')->default(false)->after('sender_type');
            $table->unsignedInteger('reply_count')->default(0)->after('is_own_comment');

            $table->json('raw_payload')->nullable()->after('reply_count');

            $table->foreign('parent_id')->references('id')->on('social_comments')->nullOnDelete();
            $table->foreign('root_id')->references('id')->on('social_comments')->nullOnDelete();

            $table->index(['platform', 'platform_comment_id']);
            $table->index(['platform', 'platform_parent_id']);
            $table->index(['root_id']);
            $table->index(['parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_comments', function (Blueprint $table) {
            //
        });
    }
};
