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

use EasyWeChat\Kernel\Support\AES;
use EasyWeChat\Kernel\Exceptions\RuntimeException;
use EasyWeChat\Kernel\Exceptions\DecryptException;
use EasyWeChat\Kernel\Exceptions\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Trait InteractsWithV3Api.
 */
trait InteractsWithV3Api
{
    use HasHttpRequests;

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
     * Get V3 cert.
     *
     * @return string
     *
     * @throws RuntimeException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function getV3Certificate()
    {
        if ($cert = $this->app['config']['v3_certificate'] ?? '') {
            return $cert;
        }

        $resource = $this->castResponseToType($this->request('v3/certificates'))['encrypt_certificate'] ?? [];

        if (!$cipherText = base64_decode($resource['ciphertext'], true)) {
            throw new RuntimeException("Failed to request v3 certificate.");
        }

        $cert = AES::decrypt(
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
     * Validate V3 wechat signature.
     *
     * @param ResponseInterface $response
     *
     * @throws InvalidArgumentException
     * @throws DecryptException
     */
    public function validateWechatSignature(ResponseInterface $response)
    {
        $signature = $response->getHeaderLine('Wechatpay-Signature');
        $timestamp = $response->getHeaderLine('Wechatpay-Timestamp');
        $nonceStr = $response->getHeaderLine('Wechatpay-Nonce');

        if (!isset($signature, $timestamp, $nonceStr)) {
            throw new InvalidArgumentException(sprintf("The Response doesn't contains signature."));
        }

        $body = $response->getBody()->getContents();

        $content = $timestamp."\n".
            $nonceStr."\n".
            $body;

        if (1 !== \openssl_verify(
            $content,
            \base64_decode($signature),
            $this->getV3Certificate(),
            'sha256WithRSAEncryption'
        )) {
            throw new DecryptException('The given payload is invalid.');
        }
    }
}
