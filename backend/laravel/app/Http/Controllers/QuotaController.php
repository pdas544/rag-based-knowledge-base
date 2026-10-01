<?php

namespace App\Http\Controllers;

use App\Models\UserQuota;
use Illuminate\Http\Request;

class QuotaController extends Controller
{
    public function show(Request $request)
    {
        $quota = UserQuota::forToday($request->user()->id);

        return response()->json([
            'prompts_used' => $quota->prompt_count,
            'prompts_limit' => 30,
            'tokens_used' => $quota->token_count,
            'tokens_limit' => 20000,
            'doc_uploads_used' => $quota->doc_upload_count,
            'doc_uploads_limit' => 10,
        ]);
    }
}
