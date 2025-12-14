<?php declare(strict_types=1);

namespace Imbo\BehatApiExtension\Context;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Imbo\BehatApiExtension\ArrayContainsComparator;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * @phpstan-require-implements ApiClientAwareContext
 * @phpstan-require-implements ArrayContainsComparatorAwareContext
 */
trait BaseApiContextTrait
{
    /**
     * Guzzle client
     */
    protected ClientInterface $client;

    /**
     * Base URI used by the Guzzle client
     */
    protected string $baseUri;

    /**
     * Request instance
     *
     * The request instance will be created once the client is ready to send it.
     */
    protected RequestInterface $request;

    /**
     * Request options
     *
     * Options to send with the request.
     *
     * @var array{
     *   auth:array<string>,
     *   form_params:array<string,string|array<string>>,
     *   multipart:array<array{name:string,contents:string|resource,filename?:string}>,
     *   query:array<string,mixed>
     * }
     */
    protected array $requestOptions = [
        'auth' => [],
        'form_params' => [],
        'multipart' => [],
        'query' => [],
    ];

    /**
     * Response instance
     *
     * The response object will be set once the request has been made.
     */
    protected ?ResponseInterface $response = null;

    /**
     * Instance of the comparator that handles matching of JSON
     */
    protected ArrayContainsComparator $arrayContainsComparator;

    /**
     * Does HTTP method has been manually set
     */
    protected bool $forceHttpMethod = false;

    /**
     * Error message used when a required response instance if missing
     */
    protected const string MISSING_RESPONSE_ERROR = 'The request has not been made yet, so no response object exists.';

    /**
     * Set the client instance
     *
     * @param array<mixed> $config
     * @throws InvalidArgumentException
     */
    public function initializeClient(array $config): self
    {
        if (!array_key_exists('base_uri', $config) || !is_string($config['base_uri']) || '' === trim($config['base_uri'])) {
            throw new InvalidArgumentException('base_uri is missing');
        }

        $this->baseUri = $config['base_uri'];
        $this->client = new Client($config);
        $this->request = new Request('GET', $this->baseUri);
        return $this;
    }

    /**
     * Set the array contains comparator instance
     */
    public function setArrayContainsComparator(ArrayContainsComparator $comparator): self
    {
        $this->arrayContainsComparator = $comparator;

        return $this;
    }

    /**
     * Add an element to the multipart array
     *
     * @param array{name:string,contents:resource|string,filename?:string} $part The part to add
     */
    private function addMultipartPart(array $part): static
    {
        $this->requestOptions['multipart'][] = $part;

        return $this;
    }

    /**
     * Send the current request and set the response instance
     *
     * @throws RequestException
     */
    protected function sendRequest(): static
    {
        if (!empty($this->requestOptions['form_params']) && !$this->forceHttpMethod) {
            $this->setRequestMethod('POST');
        }

        if (!empty($this->requestOptions['multipart']) && !empty($this->requestOptions['form_params'])) {
            // We have both multipart and form_params set in the request options. Take all
            // form_params and add them to the multipart part of the option array as it's not
            // allowed to have both.
            foreach ($this->requestOptions['form_params'] as $name => $contents) {
                if (is_array($contents)) {
                    // The contents is an array, so use array notation for the part name and store
                    // all values under this name
                    $name .= '[]';

                    foreach ($contents as $content) {
                        $this->requestOptions['multipart'][] = [
                            'name' => $name,
                            'contents' => $content,
                        ];
                    }
                } else {
                    $this->requestOptions['multipart'][] = [
                        'name' => $name,
                        'contents' => $contents,
                    ];
                }
            }

            $this->requestOptions['form_params'] = [];
        }

        try {
            $this->response = $this->client->send(
                $this->request,
                array_filter($this->requestOptions),
            );
        } catch (RequestException $e) {
            $this->response = $e->getResponse();

            if (!$this->response) {
                throw $e;
            }
        }

        return $this;
    }

    /**
     * Require a response object
     *
     * @throws RuntimeException
     */
    protected function requireResponse(): void
    {
        if (!$this->response) {
            throw new RuntimeException('The request has not been made yet, so no response object exists.');
        }
    }

    /**
     * Update the path of the request
     *
     * @param string $path The path to request
     */
    protected function setRequestPath(string $path): static
    {
        $base = Utils::uriFor($this->baseUri);
        $uri = UriResolver::resolve($base, Utils::uriFor($path));
        $this->request = $this->request->withUri($uri);

        return $this;
    }

    /**
     * Update the HTTP method of the request
     *
     * @param string $method The HTTP method
     * @param bool $force Force the HTTP method. If set to false the method set CAN be
     *                       overridden (this occurs for instance when adding form parameters to the
     *                       request, and not specifying HTTP POST for the request)
     */
    protected function setRequestMethod(string $method, bool $force = true): static
    {
        $this->request = $this->request->withMethod($method);
        $this->forceHttpMethod = $force;

        return $this;
    }
}
