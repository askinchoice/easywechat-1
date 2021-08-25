<?php

/*
 * This file is part of the overtrue/wechat.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace EasyWeChat\Tests\Kernel\Traits;

use EasyWeChat\Kernel\ServiceContainer;
use EasyWeChat\Kernel\Traits\InteractsWithV3Api;
use EasyWeChat\Payment\ProfitSharingV3\Client;
use EasyWeChat\Tests\TestCase;
use EasyWeChat\Kernel\Exceptions\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class InteractsWithV3ApiTest extends TestCase
{
    public function testGenerateToken()
    {
        $cls = new DummyClassForInteractsWithV3ApiTest();

        $merchantId = 'wx123456';
        $nonceStr = uniqid();
        $timestamp = time();
        $serialNumber = 'foobar_123456';
        $signature = 'string';

        $expected = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%d",serial_no="%s",signature="%s"',
            $merchantId,
            $nonceStr,
            $timestamp,
            $serialNumber,
            $signature,
        );

        $this->assertSame($expected, $cls->generateToken($merchantId, $nonceStr, $timestamp, $serialNumber, $signature));
    }

    public function testGetContents()
    {
        $cls = new DummyClassForInteractsWithV3ApiTest();
        $request = \Mockery::mock(RequestInterface::class);

        $request->expects()->getMethod()->andReturn('POST');
        $request->expects()->getRequestTarget()->times(3)->andReturn('api/v3/foobar');
        $request->expects()->getBody()->times(2)->andReturn(json_encode(['foo' => 'bar']));
        $nonceStr = uniqid();
        $timestamp = time();

        $this->assertSame(
            "POST\n".
            "api/v3/foobar\n".
            $timestamp."\n".
            $nonceStr."\n".
            json_encode(['foo' => 'bar'])."\n",
            $cls->getContents($request, $timestamp, $nonceStr)
        );

        $request->expects()->getMethod()->andReturn('PUT');

        $this->assertSame(
            "PUT\n".
            "api/v3/foobar\n".
            $timestamp."\n".
            $nonceStr."\n".
            json_encode(['foo' => 'bar'])."\n",
            $cls->getContents($request, $timestamp, $nonceStr)
        );

        $request->expects()->getMethod()->andReturn('GET');

        $this->assertSame(
            "GET\n".
            "api/v3/foobar\n".
            $timestamp."\n".
            $nonceStr."\n".
            "\n",
            $cls->getContents($request, $timestamp, $nonceStr)
        );
    }

    public function testValidateWechatSignature()
    {
        $app = new ServiceContainer([
            'v3_certificate' => 'string',
        ]);

        $cls = new DummyClassForInteractsWithV3ApiTest($app);

        $response = \Mockery::mock(ResponseInterface::class);

        $response->expects()->getHeaderLine()->withAnyArgs()->times(4)->andReturn(null);

        try {
            $cls->validateWechatSignature($response);
        } catch (\Exception $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame("The Response doesn't contains signature.", $e->getMessage());
        }

        $response->expects()->getHeaderLine('Wechatpay-Signature')->andReturn(\base64_encode('signature'));
        $response->expects()->getHeaderLine('Wechatpay-Serial')->andReturn('string');
        $response->expects()->getHeaderLine('Wechatpay-Timestamp')->andReturn('1629414003');
        $response->expects()->getHeaderLine('Wechatpay-Nonce')->andReturn('nonceStr');
        $response->expects()->getBody()->andReturn(json_encode(['foo' => 'bar']));

        try {
            $cls->validateWechatSignature($response);
        } catch (\Exception $e) {
            $this->assertSame("openssl_verify(): Supplied key param cannot be coerced into a public key", $e->getMessage());
        }

        $response->expects()->getHeaderLine('Wechatpay-Signature')->andReturn('MpKYv9XkD2zgqkqMgxH7cpDHkNOzC+JEYdom6b6XRxZmdkGArxUzxoPCmfnhjFuyKRjub4nkUDsfqasuJJpkVF0yYbj8avhb0lapOvSrqVcLCQbT2NCxxJk3l/ceUfaQKSokbBBYIfrXMYzcdmXzy1iq0zRQaggCjutSqZ4RxtMdwt2DtQY08CAdiFDgfpvNkFgul8/LHGOMdsaLeBeEQVTTJNewtPwPSfjQvxCwMSlSfuik8ulK2qak+5/BMgc6agXrCTt2RteK7lQbhQ4EO5snLyirmO3JI5zCqa4Hp/CXuHQBGWnTkc73qauZCXM3hDZhNsS7yeHYd42TFVzlUA==');
        $response->expects()->getHeaderLine('Wechatpay-Serial')->andReturn('384AA39043F718B081647ECE653266EE2484450B');
        $response->expects()->getHeaderLine('Wechatpay-Timestamp')->andReturn('1629874820');
        $response->expects()->getHeaderLine('Wechatpay-Nonce')->andReturn('88f7b2d9e69126b70467c29b5408736f');
        $response->expects()->getBody()->andReturn('{"code":"NO_AUTH","message":"无分账权限"}');

        $app['config']->set('v3_certificate', openssl_pkey_get_public("file://".\STUBS_ROOT.'/files/v3-certificate.pem'));

        $this->assertInstanceOf(ResponseInterface::class, $cls->validateWechatSignature($response));
    }

    public function testGetV3Certificate()
    {
        $resource = [
            'effective_time' => '2019-11-19T10:32:31+08:00',
            'encrypt_certificate' => [
                'algorithm' => 'AEAD_AES_256_GCM',
                'associated_data' => 'certificate',
                'ciphertext' => 'YtdnWg7NRB18QNTOqVCkUrH3rLccKYe3tzVhpM8NIgtYdMcCCkQv9QOJJzBLLhYUTnwQrtbK7PfEofkAWBhj5IxFc3xLsR2dtjK09RO+YOTPnR5V1kFmC7eCZ885FWP5DpmWfZiSAFzsKMK4HE/VjlWCLCY7Mee8zTRrZdfcJSwVjrdZdnX67t+Q4gh5IS8pMcqPnWdmvKHDXLeGoET1cSOzD65L5qWj33NLeazLcdP+g7Js7zTSreYtk79d1tF8xgdb+n7QT3KB8sOmxFCc6icyVh/xMIxi+McTwiAnG+7UuzfPYhXpINIJWKaGr8UoYQkKs/mRWb3oMR53udEjEWe8olN9Sy8a7Q9xTLdvmngeCOEiGIDANcIAc3Nl+XKfilxwgLg+Cwr9nx3ZJnX10JGL5SyZUPy21ckyxCz8/Jwz8+sWlmjJBGcy938JOL8tw2JNdNFPQFEWy36uiBJ38Vgev3Krm3r6bN7c4ugdW9f7YJxbXkSAfGvv768nUVYCCs4JVxGzKtR4Eim6fmSfiezbMe/Hmx4l/jFBHTXSmQm6ZpnNqUygTOEWvuqCn3fE1bTY7PohDRWYvL3AFeMYEHM3qqOw39oEb/zfaQsDvLiKKGwv/WPNNRy4SECc2j/ywQYaxcwj6bhKJEDJd2FDtKLFnZLQ+McdvD1TRULt/JDkCtDiyUKXzTSz/A8/45mE/w/+RkuAznOTrV7C98hHru27XszXKavNXagpdTrffs7f/KNl1LduJs8N5C86mvdacGwjG6V1KOXnnrQruP70UOKBNqrVZndAc9Y1IJqJmyEPHLCXgjzrlPMddIz4rCKEr0WM7LDJIRd5mGsOZCIdh2QG1YRW/mdv5H1mX/HPBJT3+NYT45iis/sJdd/UZoaDb0ni9/1gbkOSuGS2/5DAGoEWZrbOsFIHm4t9zmw7YPBjnXIsoe7ETgACsD+DURQXjyxbKz4xHM6xEwG/lNkCRA5Z/m9bUIXN1Hy9AFx1PJ7ahqVvpEVYtizzhz48+jdMzqCS3mygh6iMhtHyl3mZ10V80Qw2XF5X7DC5eY1KwaZHNMDVlymqzuMBYYswl5Rtymi9M4D0lSucWG1RfeMOJ7I2/nkHqfOdvN0iCxSwJwusQo0K6lg50gw1+Dlnir9hJAhSTFr1ifkSGZmLrs6x/Mm3Ah+bzZEpHNXdVl90EDj5WZgrFAWgvorsIwqyu0BW8Iw/bomeEPRsqIhRDIY1T3GTOjbUwUyfKgeN/3vNXcEOLbgCh8fYuX+B7xOaNPbWxOgw74g5WaMqe2ZA0+qcdkZMG+hfEgcsT3L2ye1H7EXmaedAyRVNUuG5xqsaj3RPwlSDCxKnEcOur/jrO2loO76haGabaiwG5dORfKGH0xC/rbUNmmfQGxcjNA3gKYf+JAhsblJy7I7wOMBRdk+X9b6FXV0/cGNOzqJoEpCz6FlDGJ9dz0CFY0qQCMjRxlEHQBuMndkMgZXCDk8aKOHtV/W78thMFfR+HktfhelGOj/Y6QFLQyncGEBZ0rPGtzac1Y3NZPXmyJucTo93CJ8qD6jUHHE/CwZk81iIawQi26Y3H+JqeKXzJU6N+dE/WH2tIAe/84rznFJ0KqvqVBrjPRfrbKQgn4WplGmE3XB7vISQhHfGymU3DvCNam84shi6x4mkPIDX9um07Y19oLGXMO1MC4vrZ+o3oxDIrsu2+FnEfQVL7xCjYdJTevnBEnI68RFBD5H2r33gsg05OVb7hWDUIu9vrYIaKqOOfSB1ChrDnxl0lK68JPxm/VPj58slOz7Zq6UkHKzia5fCQ4a9xbQobNCgRqN+wLrd2m8B3r+W8Q+hmCNKyXFh54I2OCNLeCDFShemlAJth7PFviRw6v4Sb6mV5Q==',
                'nonce' => 'e4abbcd0e91e',
            ],
            'expire_time' => '2024-11-17T10:32:31+08:00',
            'serial_no' => '384AA39043F718B081647ECE653266EE2484450B',
        ];

        $client = \Mockery::mock(Client::class);

        $app = new ServiceContainer([
            'v3_key' => 'apiv320210817qwertyuiopasdfghjkl',
        ], [
            'profit_sharing_v3' => $client
        ]);

        $cls = new DummyClassForInteractsWithV3ApiTest($app);

        $client->expects()->requestV3Certificates()->andReturn(['data' => [$resource]]);

        $this->assertSame(
            file_get_contents("file://".\STUBS_ROOT."/files/v3-certificate.pem"),
            $cls->getV3Certificate('384AA39043F718B081647ECE653266EE2484450B')
        );
    }
}

class DummyClassForInteractsWithV3ApiTest
{
    use InteractsWithV3Api;

    public function __construct(ServiceContainer $app = null)
    {
        $this->app = $app;
    }
}
