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
use EasyWeChat\Tests\TestCase;
use EasyWechat\Kernel\Exceptions\InvalidArgumentException;
use EasyWechat\Kernel\Exceptions\DecryptException;
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
        $certPath = \STUBS_ROOT.'/files/public-wx123456.pem';
        $app = new ServiceContainer([
            'v3_cert_path' => $certPath,
        ]);

        $cls = new DummyClassForInteractsWithV3ApiTest($app);

        $response = \Mockery::mock(ResponseInterface::class);

        $response->expects()->getHeaderLine()->withAnyArgs()->times(3)->andReturn(null);

        try {
            $cls->validateWechatSignature($response);
        } catch (\Exception $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame("The Response doesn't contains signature.", $e->getMessage());
        }

        $response->expects()->getHeaderLine('Wechatpay-Signature')->andReturn(\base64_encode('signature'));
        $response->expects()->getHeaderLine('Wechatpay-Timestamp')->andReturn('1629414003');
        $response->expects()->getHeaderLine('Wechatpay-Nonce')->andReturn('nonceStr');
        $response->shouldReceive('getBody->getContents')->andReturn(json_encode(['foo' => 'bar']));

        try {
            $cls->validateWechatSignature($response);
        } catch (\Exception $e) {
            $this->assertInstanceOf(DecryptException::class, $e);
            $this->assertSame('The given payload is invalid.', $e->getMessage());
        }
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
