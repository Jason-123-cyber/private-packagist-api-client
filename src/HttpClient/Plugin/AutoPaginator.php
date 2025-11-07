<?php declare(strict_types=1);

/**
 * (c) Packagist Conductors GmbH <contact@packagist.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PrivatePackagist\ApiClient\HttpClient\Plugin;

use Composer\Pcre\Preg;
use Http\Client\Common\Plugin;
use Http\Message\RequestFactory;
use PrivatePackagist\ApiClient\HttpClient\Message\ResponseMediator;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class AutoPaginator implements Plugin
{
    use Plugin\VersionBridgePlugin;

    /** @var RequestFactory|RequestFactoryInterface */
    private $requestFactory;
    /** @var StreamFactoryInterface */
    private $streamFactory;
    /** @var ResponseMediator */
    private $responseMediator;

    /**
     * @param RequestFactory|RequestFactoryInterface $requestFactory
     */
    public function __construct(
        $requestFactory,
        StreamFactoryInterface $streamFactory,
        ResponseMediator $responseMediator
    ) {
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->responseMediator = $responseMediator;
    }

    protected function doHandleRequest(RequestInterface $request, callable $next, callable $first)
    {
        return $next($request)->then(function (ResponseInterface $response) use ($first) {
            if (!$response->hasHeader('Link')) {
                return $response;
            }

            $next = $this->parseLinkHeader($response->getHeaderLine('Link'), 'next');
            if (!$next) {
                return $response;
            }

            $nextResponse = $first($this->requestFactory->createRequest('GET', $next))->wait();

            if ($nextResponse->getStatusCode() !== 200) {
                return $nextResponse;
            }

            return $response
                ->withoutHeader('Link')
                ->withBody($this->streamFactory->createStream(json_encode(array_merge(
                    $this->responseMediator->getContent($response),
                    $this->responseMediator->getContent($nextResponse)
                ))));
        });
    }

    private function parseLinkHeader(string $header, string $type): ?string
    {
        foreach (explode(',', $header) as $relation) {
            if (Preg::isMatch('/<(.*)>; rel="(.*)"/i', \trim($relation, ','), $match)) {
                /** @var string[] $match */
                if (3 === count($match) && $match[2] === $type) {
                    return $match[1];
                }
            }
        }

        return null;
    }
}
