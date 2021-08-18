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

use EasyWeChat\Kernel\Traits\InteractsWithV3Api;
use EasyWeChat\Tests\TestCase;
use Psr\Http\Message\RequestInterface;

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

        $request->expects()->getMethod()->times(2)->andReturn('POST');
        $request->expects()->getRequestTarget()->andReturn('api/v3/foobar');
        $request->expects()->getBody()->andReturn(['foo' => 'bar']);
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
    }
}

class DummyClassForInteractsWithV3ApiTest
{
    use InteractsWithV3Api;
}
