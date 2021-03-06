<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use App\Http\Requests\Api\SocialAuthorizationRequest;
use Illuminate\Support\Arr;
use Overtrue\LaravelSocialite\Socialite;
use App\Models\User;
use App\Http\Requests\Api\AuthorizationRequest;
use Illuminate\Support\Facades\Auth;

class AuthorizationsController extends Controller
{
    public function socialStore($type, SocialAuthorizationRequest $request)
    {
        $driver = Socialite::create($type);

        try {
            if ($code = $request->code) {
                $oauthUser = $driver->userFromCode($code);
            } else {
                // 微信需要增加 openid
                if ($type == 'wechat') {
                    $driver->withOpenid($request->openid);
                }

                $oauthUser = $driver->userFromToken($request->access_token);
            }
        } catch (\Exception $e) {
           throw new AuthenticationException('参数错误，未获取用户信息');
        }

        if (!$oauthUser->getId()) {
           throw new AuthenticationException('参数错误，未获取用户信息');
        }

        switch ($type) {
            case 'wechat':
                $unionid = $oauthUser->getRaw()['unionid'] ?? null;

                if ($unionid) {
                    $user = User::where('weixin_unionid', $unionid)->first();
                } else {
                    $user = User::where('weixin_openid', $oauthUser->getId())->first();
                }

                // 没有用户，默认创建一个用户
                if (!$user) {
                    $user = User::create([
                        'name' => $oauthUser->getNickname(),
                        'avatar' => $oauthUser->getAvatar(),
                        'weixin_openid' => $oauthUser->getId(),
                        'weixin_unionid' => $unionid,
                    ]);
                }

                break;
        }

        // TODO 这里为什么就可以获取token了呢？
        // 补充：auth('api')  等同于 Auth::guard('api')，在 config 的 auth.php 文件这中配置
        // 对应的值是jwt，
        $token = auth('api')->login($user);
        return $this->responseWithToken($token)->setStatusCode(201);

        return response()->json(['token' => $user->id]);
    }

    public function store(AuthorizationRequest $request)
    {
        $username = $request->username;

        filter_var($username, FILTER_VALIDATE_EMAIL) ?
            $credentials['email'] = $username :
            $credentials['phone'] = $username;

        $credentials['password'] = $request->password;

        if (!$token = Auth::guard('api')->attempt($credentials)) {
            throw new AuthenticationException('用户名或密码错误');
        }

        return $this->responseWithToken($token)->setStatusCode(201);

    }

    public function responseWithToken($token){
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            // TODO 这里为什么可以直接使用 factory 方法呢？
            // auth('api')->factory() 等同于调用了 Tymon\JWTAuth\JWTGuard 中的 factory 方法？
            'expires_in' => auth('api')->factory()->getTTL() *60
        ]);
    }

    public function update()
    {
        $token = auth('api')->refresh();
        return $this->responseWithToken($token);
    }

    public function destroy()
    {
        auth('api')->logout();
        return response(null, 204);
    }
}
