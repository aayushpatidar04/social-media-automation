<?php

return [
    // Only sync posts published in the last N days
    'post_window_days' => env('SYNC_POST_WINDOW_DAYS', 30),

    // Only sync comments from the last N days
    'comment_window_days' => env('SYNC_COMMENT_WINDOW_DAYS', 7),

    // Only auto-reply to comments from the last N days
    'auto_reply_window_days' => env('SYNC_AUTO_REPLY_WINDOW_DAYS', 1),
];