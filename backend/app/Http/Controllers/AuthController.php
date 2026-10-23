<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * SPA 向けのクッキー（セッション）認証。トークンは発行しない。
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        if (! Auth::guard('web')->attempt($request->validated())) {
            // どちらが違うかは教えない（登録済みのメールアドレスを探られないようにするため）
            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが違います。',
            ]);
        }

        // セッション固定攻撃への対策：ログイン前のセッション ID を使い回さない
        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        // セッションを破棄し、CSRF トークンも作り直す
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
