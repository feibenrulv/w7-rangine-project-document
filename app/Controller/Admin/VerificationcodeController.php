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

namespace W7\App\Controller\Admin;

use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use W7\Http\Message\Server\Request;

class VerificationcodeController extends Controller
{
	protected $codeNum = 4;

	/**
	 * 获取验证码图片
	 * @return false|string
	 */
	public function getCodeimg(Request $request)
	{
		try {
			$phrase = new PhraseBuilder;

			$code = $phrase->build($this->codeNum);

			$builder = new CaptchaBuilder($code, $phrase);

			$builder->setBackgroundColor(220, 210, 230);
			$builder->setMaxAngle(25);
			$builder->setMaxBehindLines(0);
			$builder->setMaxFrontLines(0);
			$builder->build();
			$phrase = $builder->getPhrase();

			ob_start();
			$builder->output();
			$img = ob_get_contents();
			ob_end_clean();

			$request->session->set('img_code', $phrase);
			$img = 'data:image/jpg;base64,'.base64_encode($img);
			$data = [
				'img' => $img
			];
			return $this->success($data);
		} catch (\Exception $e) {
			return $this->error($e->getMessage());
		}
	}
}
