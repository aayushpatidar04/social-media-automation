<?php

return [
    // How far back to fetch comments (in days).
    // 0 = no limit (full sync mode).
    // Normal sync default: 7 days.
    'comment_window_days' => env('SYNC_COMMENT_WINDOW_DAYS', 7),
];
