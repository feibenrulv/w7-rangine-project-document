<?php

/**
 * WeEngine Document System
 *
 * (c) We7Team 2019 <https://www.w7.cc>
 *
 * This is not a free software
 * Using it under the license terms
 * visited https://www.w7.cc for more details
 */

namespace W7\App\Controller\Common;

use Overtrue\Socialite\Config;
use Overtrue\Socialite\SocialiteManager;
use Throwable;
use W7\App\Controller\BaseController;
use W7\App\Exception\ErrorHttpException;
use W7\App\Model\Entity\User;
use W7\App\Model\Entity\UserThirdParty;
use W7\App\Model\Logic\OauthLogic;
use W7\App\Model\Logic\ThirdPartyLoginLogic;
use W7\App\Model\Logic\UserLogic;
use W7\Http\Message\Server\Request;

class AuthController extends BaseController
{
	public function login(Request $request)
	{
		$data = $this->validate($request, [
			'username' => 'required',
			'userpass' => 'required',
			'code' => 'required',
		], [
			'username.required' => '用户名不能为空',
			'userpass.required' => '密码不能为空',
			'code.required' => '验证码不能为空',
		]);
		$code = $request->session->get('img_code');
		if (strtolower($data['code']) != strtolower($code)) {
			throw new ErrorHttpException('请输入正确的验证码');
		}

		$user = UserLogic::instance()->getByUserName($data['username']);
		if (empty($user)) {
			throw new ErrorHttpException('用户名或密码错误，请检查');
		}

		if ($user->userpass != UserLogic::instance()->userPwdEncryption($user->username, $data['userpass'])) {
			throw new ErrorHttpException('用户名或密码错误，请检查');
		}

		if (!empty($user->is_ban)) {
			throw new ErrorHttpException('您使用的用户已经被禁用，请联系管理员');
		}

		$request->session->destroy();

		$request->session->set('user', [
			'uid' => $user->id,
			'username' => $user->username,
		]);

		return $this->data('success');
	}

	public function logout(Request $request)
	{
		$request->session->destroy();
		return $this->data('success');
	}

	public function method(Request $request) {
		$redirectUrl = $request->post('redirect_url');
		$setting = ThirdPartyLoginLogic::instance()->getThirdPartyLoginSetting();
		$data = [];
		/**
		 * @var SocialiteManager $socialite
		 */
		$socialite = iloader()->get(SocialiteManager::class);
		//获取可用的第三方登录列表
		foreach($setting['channel'] as $key => $item) {
			if (!empty($item['setting']['enable'])) {
				$redirectUrl = '';
				try{
					$redirectUrl = $socialite->config(new Config([
						'client_id' =>  $item['setting']['app_id'],
						'client_secret' =>  $item['setting']['secret_key'],
						'redirect_url' => ienv('API_HOST') . 'login?app_id=' . $key . '&redirect_url=' . $redirectUrl
					]))->driver($key)->stateless()->redirect()->getTargetUrl();
				} catch(Throwable $e) {
					
				}

				$data[] = [
					'name' => $item['setting']['name'],
					'logo' => $item['setting']['logo'],
					'redirect_url' => $redirectUrl
				];
			}
		}
		return $this->data($data);
	}

	public function user(Request $request)
	{
		$userSession = $request->session->get('user');
		/**
		 * @var User $user
		 */
		$user = UserLogic::instance()->getByUid($userSession['uid']);
		if (!$user) {
			$request->session->destroy();
			throw new ErrorHttpException('请先登录', [], 444);
		}

		$result = [
			'id' => $user->id,
			'username' => $user->username,
			'created_at' => $user->created_at->toDateTimeString(),
			'updated_at' => $user->updated_at->toDateTimeString(),
			'no_password' => empty($user->userpass),
			'acl' => [
				'has_manage' => $user->isFounder
			]
		];

		return $this->data($result);
	}

	public function update(Request $request)
	{
		/**
		 * @var User $user
		 */
		$user = $request->getAttribute('user');

		$userName = trim($request->post('username'));
		$userOldPass = trim($request->post('old_userpass'));
		$userPass = trim($request->post('userpass'));
		if (empty($userName) || empty($userPass)) {
			throw new ErrorHttpException('参数错误');
		}
		if ($userOldPass && $user->userpass != UserLogic::instance()->userPwdEncryption($user->username, $userOldPass)) {
			throw new ErrorHttpException('旧密码错误');
		}
		
		$user['id'] = $user->id;
		$user['username'] = empty($userName) ? $user->username : $userName;
		$userPass && $user['userpass'] = $userPass;
		try {
			$res = UserLogic::instance()->updateUser($user);
			return $this->data($res);
		} catch (\Throwable $e) {
			throw new ErrorHttpException($e->getMessage());
		}
	}

	public function thirdPartyLogin(Request $request) {
		$code = $request->input('code');
		if (empty($code)) {
			throw new ErrorHttpException('Code码错误');
		}
		$appId = $request->input('app_id');
		if (empty($appId)) {
			throw new ErrorHttpException('app_id错误');
		}

		$setting = ThirdPartyLoginLogic::instance()->getThirdPartyLoginChannelById($id);
		if (!$setting) {
			throw new ErrorHttpException('不支持该授权方式');
		}
		/**
		 * @var SocialiteManager $socialite
		 */
		$socialite = iloader()->get(SocialiteManager::class);
		$driver = $socialite->config(new Config([
			'client_id' => $setting['setting']['app_id'],
			'client_secret' => $setting['setting']['secret_key']
		]))->driver($appId)->stateless();

		$user = $driver->user($driver->getAccessToken($code));
		//添加QQ用户数据
		$userInfo = $user->getOriginal();
		if (empty($userInfo['username']) || empty($userInfo['uid'])) {
			throw new ErrorHttpException('登录用户数据错误，请重试');
		}

		$loginSetting = ThirdPartyLoginLogic::instance()->getDefaultLoginSetting();
		$user = OauthLogic::instance()->getThirdPartyUserByUsernameUid($userInfo['uid'], $userInfo['username']);
		if (empty($user)) {
			if (empty($loginSetting['is_need_bind'])) {
				$localUsername = 'tpl_' . $userInfo['username'] . $userInfo['uid'];
				$uid = UserLogic::instance()->createBucket($localUsername);
			} else {
				$localUsername = $userInfo['username'];
				$uid = 0;
			}
			
			$thirdPartyUser = UserThirdParty::query()->create([
				'openid' => $userInfo['uid'],
				'username' => $userInfo['username'],
				'uid' => $uid,
				'source' => $appId,
			]);

			$localUser = [
				'uid' => $uid,
				'third-party-uid' => $thirdPartyUser->id,
				'username' => $localUsername,
			];
		} else {
			$localUser = [
				'uid' => $user->bindUser->id,
				'username' => $user->bindUser->username,
			];
		}

		$request->session->destroy();
		if (!empty($loginSetting['is_need_bind']) && empty($user)) {
			$request->session->set('third-party-user', $localUser);
			return $this->data([
				'is_need_bind' => true
			]);
		} else {
			$request->session->set('user', $localUser);
			return $this->data('success');
		}
	}
	
	public function thirdPartyLoginBind(Request $request)
	{
		$data = $this->validate($request, [
			'username' => 'required',
			'userpass' => 'required'
		], [
			'username.required' => '用户名不能为空',
			'userpass.required' => '密码不能为空'
		]);
		$thirdPartyUser = $request->session->get('third-party-user');
		if (!$thirdPartyUser) {
			throw new ErrorHttpException('非法请求');
		}

		$user = UserLogic::instance()->getByUserName($data['username']);
		if (empty($user)) {
			throw new ErrorHttpException('用户名或密码错误，请检查');
		}

		if ($user->userpass != UserLogic::instance()->userPwdEncryption($user->username, $data['userpass'])) {
			throw new ErrorHttpException('用户名或密码错误，请检查');
		}

		if (!empty($user->is_ban)) {
			throw new ErrorHttpException('您使用的用户已经被禁用，请联系管理员');
		}

		UserThirdParty::query()->where('id', '=', $thirdPartyUser['third-party-uid'])->update([
			'uid' => $user->id,
		]);

		$request->session->destroy();
		$request->session->set('user', [
			'uid' => $user->id,
			'username' => $user->username,
		]);

		return $this->data('success');
	}
}
