<?php

/*
 * This file is part of the overtrue/wechat.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace EasyWeChat\Tests\Payment\ProfitSharingV3;

use EasyWeChat\Payment\Application;
use EasyWeChat\Payment\ProfitSharingV3\Client;
use EasyWeChat\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\HandlerStack;

class ClientTest extends TestCase
{
    protected function app()
    {
        return new Application([
            'app_id' => 'wx123456',
            'brand_mchid' => 'foo-brand-mchid',
            'sub_mchid' => '1900000109',
        ]);
    }

    public function testShare()
    {
        $client = $this->mockApiClient(
            Client::class,
            ['requestToV3'],
            $this->app()
        );

        $client->expects()->requestToV3(
            'v3/brand/profitsharing/orders',
            [
                'appid' => 'wx123456',
                'transaction_id' => '4208450740201411110007820472',
                'out_order_no' => 'P20150806125346',
                'receivers' => [[
                    'type' => 'MERCHANT_ID',
                    'account' => '190001001',
                    'amount' => 100,
                    'description' => '分到商户',
                ], [
                    'type' => 'PERSONAL_WECHATID',
                    'account' => '86693952',
                    'amount' => 888,
                    'description' => '分到个人',
                ]],
                "finish" => false,
            ]
        )->andReturn('mock-result');

        $this->assertSame('mock-result', $client->share(
            '4208450740201411110007820472',
            'P20150806125346',
            [[
                'type' => 'MERCHANT_ID',
                'account' => '190001001',
                'amount' => 100,
                'description' => '分到商户',
            ], [
                'type' => 'PERSONAL_WECHATID',
                'account' => '86693952',
                'amount' => 888,
                'description' => '分到个人',
            ]]
        ));
    }

    public function testRequestV3Certificates()
    {
        $client = $this->mockApiClient(
            Client::class,
            ['castResponseToType', 'performRequest', 'generateHandlerStack'],
            $this->app()
        );

        $client->expects()->castResponseToType(ResponseInterface::class, null)->andReturn('mock-result');

        $client->expects()->performRequest(
            'https://api.mch.weixin.qq.com/v3/certificates',
            'GET',
            ['handler' => \Mockery::mock(HandlerStack::class)]
        )->andReturn(\Mockery::mock(ResponseInterface::class));

        $client->expects()->generateHandlerStack('client_headers', 'wechat_authorized', 'log')->andReturn(\Mockery::mock(HandlerStack::class));

        $this->assertSame('mock-result', $client->requestV3Certificates());
    }
}
