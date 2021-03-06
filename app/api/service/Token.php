<?php


namespace app\api\service;


use app\api\libs\exceptions\TokenException;
use app\api\libs\exceptions\WeChatException;
use app\api\model\User;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key;
use think\facade\Config;
use think\facade\Request;

class Token
{
    protected $code;
    protected $appid;
    protected $appsecret;
    protected $loginUrl;

    function __construct($code)
    {
        $this->code = $code;
        $this->appid = Config::get('wechat.APPID');
        $this->appsecret = Config::get('wechat.APPSECRET');
        $this->loginUrl = sprintf(Config::get('wechat.LOGIN_URL'),
            $this->appid, $this->appsecret, $this->code);
    }

    public function make() {
        $wxResp = $this->requestWeChat();
        $token = $this->grantToken($wxResp['openid']);
        return $token;
    }

    private function requestWeChat() {
        $resp = curl_get($this->loginUrl);

        $wxResp = json_decode($resp, true);

        if (array_key_exists('errcode', $wxResp)) {
            throw new WeChatException([
                'msg' => $wxResp['errmsg'],
                'errorCode' => $wxResp['errcode']
            ]);
        }
        return $wxResp;
    }

    private function grantToken($openid) {
        $user = User::where('openid', $openid)->find();
        if ($user) {
            $uid = $user->id;
        } else {
            $user = User::create(['openid' => $openid]);
            $uid = $user->id;
        }

        $token = $this->makeJWT($uid);
        return $token;
    }

    private function makeJWT($uid) {
        $signer = new Sha256();
        $key = new Key(Config::get('secure.JWT_KEY'));
        $time = time();
        $expires = Config::get('secure.JWT_EXPIRES') ? Config::get('secure.JWT_EXPIRES') : 3600;
        $token = (new Builder())->issuedAt($time)
                                ->expiresAt($time + $expires)
                                ->withClaim('uid', $uid)
                                ->getToken($signer, $key);
        return (string) $token;
    }

    public static function verifyJWT() {
        $signer = new Sha256();
        $parse = self::check($signer);
        $res = $parse->verify($signer, Config::get('secure.JWT_KEY'));
        return $res;
    }

    private static function check($signer) {
        $token = Request::header('token');

        if (!$token) {
            throw new TokenException();
        }

        $parse = (new Parser())->parse((string) $token);
        return $parse;
    }

    public static function getUid() {
        $signer = new Sha256();
        $parse = self::check($signer);

        $valid = $parse->verify($signer, Config::get('secure.JWT_KEY'));
        if (!$valid) {
            throw new TokenException(['msg' => 'token无效或已过期']);
        }

        $uid = $parse->getClaim('uid');
        return $uid;
    }

}