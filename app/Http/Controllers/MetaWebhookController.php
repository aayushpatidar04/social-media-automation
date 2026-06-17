<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\SocialAccount;

class MetaWebhookController extends Controller
{
    public function handle(Request $request)
    {
        if ($request->isMethod('get')) {
            return $this->verify($request);
        }

        $payload = $request->all();
        
        Log::info('Meta webhook received', $request->all());

        if ($this->hasOwnInstagramComment($payload)) {
            Log::info('Skipping own Instagram comment webhook', $payload);

            return response()->json([
                'success' => true,
                'skipped' => true,
                'reason' => 'own_instagram_comment',
            ]);
        }

        // if ($this->hasOwnFacebookPageComment($payload)) {
        //     Log::info('Skipping own Facebook page comment webhook', $payload);

        //     return response()->json([
        //         'success' => true,
        //         'skipped' => true,
        //         'reason' => 'own_facebook_page_comment',
        //     ]);
        // }

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

    private function hasOwnInstagramComment(array $payload): bool
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $from = data_get($change, 'value.from');

                if (!$from || !is_array($from)) {
                    continue;
                }

                if (array_key_exists('self_ig_scoped_id', $from)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasOwnFacebookPageComment(array $payload): bool
    {
        if (($payload['object'] ?? null) !== 'page') {
            return false;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            $pageId = (string) ($entry['id'] ?? '');

            foreach ($entry['changes'] ?? [] as $change) {
                $field = data_get($change, 'field');
                $item = data_get($change, 'value.item');
                $verb = data_get($change, 'value.verb');
                $fromId = (string) data_get($change, 'value.from.id');

                if ($field !== 'feed' || $item !== 'comment' || $verb !== 'add') {
                    continue;
                }

                if (!$pageId || !$fromId) {
                    continue;
                }

                if ($fromId !== $pageId) {
                    continue;
                }

                $accountExists = SocialAccount::where('platform', 'facebook')
                    ->where('is_active', true)
                    ->where('platform_account_id', $pageId)
                    ->exists();

                if ($accountExists) {
                    return true;
                }
            }
        }

        return false;
    }
}