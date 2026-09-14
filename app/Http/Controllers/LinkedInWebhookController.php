<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessLinkedInWebhook;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LinkedInWebhookController extends Controller
{
    /**
     * Handle incoming LinkedIn webhook events.
     *
     * LinkedIn sends a verification POST when you subscribe:
     * { "verificationCode": "...", "status": "approved" }
     *
     * You must respond with the same JSON.
     *
     * After verification, LinkedIn sends event payloads like:
     * { "eventType": "COMMENT", "entity": "urn:li:ugcPost:xxx", "lifecycleState": "CREATED", "comment": { ... } }
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        // LinkedIn verification challenge
        if (isset($payload['verificationCode']) && isset($payload['status'])) {
            Log::info('LinkedIn webhook verification received', [
                'verification_code' => $payload['verificationCode'],
                'status' => $payload['status'],
            ]);

            return response()->json([
                'verificationCode' => $payload['verificationCode'],
                'status' => $payload['status'],
            ]);
        }

        // Actual event
        if (isset($payload['eventType'])) {
            Log::info('LinkedIn webhook event received', $payload);
            ProcessLinkedInWebhook::dispatch($payload);
        }

        return response('OK', 200);
    }
}
