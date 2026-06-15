<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::info($request->all());
        if ($request->isMethod('get')) {
            return $this->verify($request);
        }

        Log::info('Meta webhook received', $request->all());

        ProcessMetaWebhook::dispatch($request->all());

        return response()->json([
            'success' => true,
        ]);
    }

    private function verify(Request $request)
    {
        $mode = $request->get('hub_mode');
        $token = $request->get('hub_verify_token');
        $challenge = $request->get('hub_challenge');

        if (
            $mode === 'subscribe' &&
            $token === env('META_WEBHOOK_VERIFY_TOKEN')
        ) {
            Log::info('Meta webhook verified');

            return response($challenge, 200);
        }

        Log::warning('Meta webhook verification failed', [
            'mode' => $mode,
            'token' => $token,
        ]);

        return response('Verification failed', 403);
    }
}