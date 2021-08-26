<?php

/*
 * This file is part of the overtrue/wechat.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace EasyWeChat\Kernel\Traits;

use EasyWeChat\Kernel\Exceptions\DecryptException;
use EasyWeChat\Kernel\Exceptions\InvalidArgumentException;
use EasyWeChat\Kernel\Support;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Middleware;

/**
 * Trait InteractsWithV3Api.
 */
trait InteractsWithV3Api
{
    /**
     * Generate V3 API token.
     *
     * @param string $merchantId
     * @param string $nonceStr
     * @param string $timestamp
     * @param string $serialNumber
     * @param string $signature
     *
     * @return string
     */
    public function generateToken(
        string $merchantId,
        string $nonceStr,
        string $timestamp,
        string $serialNumber,
        string $signature,
    ) {
        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%d",serial_no="%s",signature="%s"',
            $merchantId,
            $nonceStr,
            $timestamp,
            $serialNumber,
            $signature,
        );
    }

    /**
     * Get request contents according V3 rule.
     *
     * @param RequestInterface $request
     * @param string $timestamp
     * @param string $nonceStr
     *
     * @return string
     */
    public function getContents(RequestInterface $request, string $timestamp, string $nonceStr)
    {
        $method = $request->getMethod();

        return $method."\n".
            $request->getRequestTarget()."\n".
            $timestamp."\n".
            $nonceStr."\n".
            (strtoupper($method) === "GET" ? "" : $request->getBody())."\n";
    }

    /**
     * Validate V3 wechat signature.
     *
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     *
     * @throws InvalidArgumentException
     * @throws DecryptException
     */
    public function validateWechatSignature(ResponseInterface $response)
    {
        $signature = $response->getHeaderLine('Wechatpay-Signature');
        $timestamp = $response->getHeaderLine('Wechatpay-Timestamp');
        $nonceStr = $response->getHeaderLine('Wechatpay-Nonce');
        $serialNumber = $response->getHeaderLine('Wechatpay-Serial');

        if (!$signature || !$timestamp || !$nonceStr || !$serialNumber) {
            throw new InvalidArgumentException("The Response doesn't contains signature.");
        }

        $content = $timestamp."\n".
            $nonceStr."\n".
            $response->getBody()."\n";

        if (1 !== \openssl_verify(
            $content,
            \base64_decode($signature),
            $this->getV3Certificate($serialNumber),
            'sha256WithRSAEncryption'
        )) {
            throw new DecryptException('The given payload is invalid.');
        }

        return $response;
    }

    /**
     * Get a V3 cert.
     *
     * @param string $serialNumber
     *
     * @return string
     *
     * @throws InvalidArgumentException
     */
    public function getV3Certificate(string $serialNumber)
    {
        if ($cert = $this->app['config']['v3_certificate'] ?? '') {
            return $cert;
        }

        $data = $this->app['base']->requestV3Certificates()['data'];

        if (!$resource = Support\Arr::first($data, fn ($item) => $item['serial_no'] == $serialNumber)['encrypt_certificate'] ?? '') {
            throw new InvalidArgumentException(sprintf('Can not find the serialNo %s', $serialNumber));
        }

        $cipherText = base64_decode($resource['ciphertext'], true);

        $cert = Support\AES::decrypt(
            substr($cipherText, 0, -16),
            $this->app['config']['v3_key'],
            $resource['nonce'],
            OPENSSL_RAW_DATA,
            'aes-256-gcm',
            substr($cipherText, -16),
            $resource['associated_data']
        );

        $this->app['config']->set('v3_certificate', $cert);

        return $cert;
    }

    /**
     * Set client headers.
     *
     * @return \Closure
     */
    protected function setClientHeadersMiddleware()
    {
        return Middleware::mapRequest(
            fn (RequestInterface $request) => $request
            ->withHeader('Accept', 'application/json, text/plain, application/x-gzip')
            ->withHeader('User-Agent', 'overtrue/wechat')
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
        );
    }

    /**
     * Authorize the request.
     *
     * @return \Closure
     */
    protected function authorizedV3Middleware()
    {
        $timestamp = time();

        $nonceStr = uniqid();

        return Middleware::mapRequest(fn (RequestInterface $request) => $request->withHeader('Authorization', $this->generateToken(
            $this->app['config']['mch_id'],
            $nonceStr,
            $timestamp,
            $this->app['config']['serial_no'],
            Support\sha256_rsa_with_private_encrypt(
                $this->getContents($request, $timestamp, $nonceStr),
                openssl_pkey_get_private('file://'.$this->app['config']['v3_key_path'])
            ),
        )));
    }

    /**
     * Validate wechat V3 response Signature.
     *
     * @return \Closure
     */
    protected function validateV3SignatureMiddleware()
    {
        return Middleware::mapResponse(fn (ResponseInterface $response) => $this->validateWechatSignature($response));
    }
}
