<?php declare(strict_types=1);

/**
 * (c) Packagist Conductors GmbH <contact@packagist.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PrivatePackagist\ApiClient\HttpClient\Plugin;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Promise\FulfilledPromise;
use PrivatePackagist\ApiClient\HttpClient\Message\ResponseMediator;
use Psr\Http\Message\RequestInterface;

class AutoPaginatorTest extends PluginTestCase
{
    /** @var AutoPaginator */
    private $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new AutoPaginator(
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
            new ResponseMediator()
        );
    }

    public function testPaginate(): void
    {
        $request = new Request('POST', '/packages/', [], json_encode(['foo' => 'bar']));

        $innerResponse = new Response(
            200,
            [
                'Link' => '<https://packagist.com/api/packages?page=1>; rel="first", <https://packagist.com/api/packages?page=2>; rel="next", <https://packagist.com/api/packages/?page=2>; rel="last"',
                'Content-Type' => 'application/json',

            ],
            (string) json_encode([1])
        );

        $response = $this->plugin->handleRequest($request, function () use ($innerResponse) {
            return new FulfilledPromise($innerResponse);
        }, function (RequestInterface $request) {
            $this->assertSame('https://packagist.com/api/packages?page=2', (string) $request->getUri());
            return new FulfilledPromise(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([2])));
        })->wait();

        $this->assertNotSame($innerResponse, $response);
        $this->assertSame(json_encode([1, 2]), (string) $response->getBody()->getContents());
    }

    public function testNoLinkHeader(): void
    {
        $request = new Request('POST', '/packages/', [], json_encode(['foo' => 'bar']));

        $innerResponse = new Response();

        $response = $this->plugin->handleRequest($request, function () use ($innerResponse) {
            return new FulfilledPromise($innerResponse);
        }, $this->first)->wait();

        $this->assertSame($innerResponse, $response);
    }

    public function testNoNextLinkHeader(): void
    {
        $request = new Request('POST', '/packages/', [], json_encode(['foo' => 'bar']));

        $innerResponse = new Response(200, ['Link' => '<https://packagist.com/api/packages?page=1>; rel="first", <https://packagist.com/api/packages/?page=1>; rel="last"']);

        $response = $this->plugin->handleRequest($request, function () use ($innerResponse) {
            return new FulfilledPromise($innerResponse);
        }, $this->first)->wait();

        $this->assertSame($innerResponse, $response);
    }
}
