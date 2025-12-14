<?php declare(strict_types=1);

namespace Imbo\BehatApiExtension\Context;

use Assert\Assertion;
use Assert\AssertionFailedException as AssertionFailure;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Imbo\BehatApiExtension\ArrayContainsComparator;
use Imbo\BehatApiExtension\Exception\AssertionFailedException;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;

/**
 * Behat feature context that can be used to simplify testing of RESTful HTTP APIs
 */
class BaseApiContext implements ApiClientAwareContext, ArrayContainsComparatorAwareContext
{
    use BaseApiContextTrait;

    /**
     * Attach a file to the request
     *
     * @param string $path Path to the image to add to the request
     * @param string $partName Multipart entry name
     * @throws InvalidArgumentException If the $path does not point to a file, an exception is
     *                                  thrown
     */
    #[Given('I attach :path to the request as :partName')]
    public function addMultipartFileToRequest(string $path, string $partName): static
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(sprintf('File does not exist: "%s"', $path));
        }

        $contents = fopen($path, 'rb');

        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to open file: %s', $path));
        }

        return $this->addMultipartPart([
            'name'     => $partName,
            'contents' => $contents,
            'filename' => basename($path),
        ]);
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
     * Add multipart form parameters to the request
     *
     * @param TableNode $table Table with name / value pairs
     */
    #[Given('the following multipart form parameters are set:')]
    public function setRequestMultipartFormParams(TableNode $table): static
    {
        /** @var array<string,array{name:string,value:string}> */
        $rows = $table->getColumnsHash();

        foreach ($rows as $row) {
            $this->addMultipartPart([
                'name'     => $row['name'],
                'contents' => $row['value'],
            ]);
        }

        return $this;
    }

    /**
     * Set basic authentication information for the next request
     *
     * @param string $username The username to authenticate with
     * @param string $password The password to authenticate with
     */
    #[Given('I am authenticating as :username with password :password')]
    public function setBasicAuth(string $username, string $password): static
    {
        $this->requestOptions['auth'] = [$username, $password];

        return $this;
    }

    /**
     * Set a HTTP request header
     *
     * If the header already exists it will be overwritten
     *
     * @param string $header The header name
     * @param string $value The header value
     */
    #[Given('the :header request header is :value')]
    public function setRequestHeader(string $header, string $value): static
    {
        $this->request = $this->request->withHeader($header, $value);

        return $this;
    }

    /**
     * Set/add a HTTP request header
     *
     * If the header already exists it will be converted to an array
     *
     * @param string $header The header name
     * @param string $value The header value
     */
    #[Given('the :header request header contains :value')]
    public function addRequestHeader(string $header, string $value): static
    {
        $this->request = $this->request->withAddedHeader($header, $value);

        return $this;
    }

    /**
     * Set form parameters
     *
     * @param TableNode $table Table with name / value pairs
     */
    #[Given('the following form parameters are set:')]
    public function setRequestFormParams(TableNode $table): static
    {
        /** @var array<string,array{name:string,value:string}> */
        $rows = $table->getColumnsHash();

        foreach ($rows as $row) {
            $name  = $row['name'];
            $value = $row['value'];

            if (isset($this->requestOptions['form_params'][$name]) && !is_array($this->requestOptions['form_params'][$name])) {
                $this->requestOptions['form_params'][$name] = [
                    $this->requestOptions['form_params'][$name],
                    $value,
                ];
            } else {
                $this->requestOptions['form_params'][$name] = $value;
            }
        }

        return $this;
    }

    /**
     * Set the request body to a string
     *
     * @param resource|string|PyStringNode $string The content to set as the request body
     * @throws InvalidArgumentException If form_params or multipart is used in the request options
     *                                  an exception will be thrown as these can't be combined.
     */
    #[Given('the request body is:')]
    public function setRequestBody($string): static
    {
        if (!empty($this->requestOptions['multipart']) || !empty($this->requestOptions['form_params'])) {
            throw new InvalidArgumentException(
                'It\'s not allowed to set a request body when using multipart/form-data or form parameters.',
            );
        }

        if ($string instanceof PyStringNode) {
            $string = (string) $string;
        }

        $this->request = $this->request->withBody(Utils::streamFor($string));

        return $this;
    }

    /**
     * Set the request body to a read-only resource pointing to a file
     *
     * This step will open a read-only resource to $path and attach it to the request body. If the
     * file does not exist or is not readable the method will end up throwing an exception. The
     * method will also set the Content-Type request header. mime_content_type() is used to get the
     * mime type of the file.
     *
     * @param string $path Path to a file
     * @throws InvalidArgumentException|RuntimeException
     */
    #[Given('the request body contains :path')]
    public function setRequestBodyToFileResource(string $path): static
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(sprintf('File does not exist: "%s"', $path));
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException(sprintf('File is not readable: "%s"', $path));
        }

        /** @var resource */
        $fp = fopen($path, 'rb');

        // Set the Content-Type request header and the request body
        return $this
            ->setRequestHeader('Content-Type', (string) mime_content_type($path))
            ->setRequestBody($fp);
    }

    /**
     * Add a query parameter to the upcoming request
     *
     * @param string $name The name of the parameter
     * @param string|TableNode $value The value to add
     */
    #[Given('the query parameter :name is :value')]
    #[Given('the query parameter :name is:')]
    public function setQueryStringParameter(string $name, string|TableNode $value): static
    {
        if ($value instanceof TableNode) {
            /** @var array<string> */
            $value = array_column($value->getHash(), 'value');
        }

        $this->requestOptions['query'][$name] = $value;

        return $this;
    }

    /**
     * Set multiple query parameters for the upcoming request
     *
     * @param TableNode $params The values to set
     */
    #[Given('the following query parameters are set:')]
    public function setQueryStringParameters(TableNode $params): static
    {
        /** @var array<string,array{name:string,value:string}> */
        $rows = $params->getColumnsHash();

        foreach ($rows as $row) {
            $this->requestOptions['query'][$row['name']] = $row['value'];
        }

        return $this;
    }

    /**
     * Request a path
     *
     * @param string $path The path to request
     * @param string|null $method The HTTP method to use
     */
    #[When('I request :path')]
    #[When('I request :path using HTTP :method')]
    public function requestPath(string $path, ?string $method = null): static
    {
        $this->setRequestPath($path);

        if (null === $method) {
            $this->setRequestMethod('GET', false);
        } else {
            $this->setRequestMethod($method);
        }

        return $this->sendRequest();
    }

    /**
     * Assert the HTTP response code
     *
     * @param int|string $code The HTTP response code
     * @throws AssertionFailedException
     */
    #[Then('the response code is :code')]
    public function assertResponseCodeIs(int|string $code): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::same(
                $actual = $this->response->getStatusCode(),
                $expected = $this->validateResponseCode((int) $code),
                sprintf('Expected response code %d, got %d.', $expected, $actual),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert the HTTP response code is not a specific code
     *
     * @param int|string $code The HTTP response code
     * @throws AssertionFailedException
     */
    #[Then('the response code is not :code')]
    public function assertResponseCodeIsNot(int|string $code): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::notSame(
                $actual = $this->response->getStatusCode(),
                $this->validateResponseCode((int) $code),
                sprintf('Did not expect response code %d.', $actual),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the HTTP response reason phrase equals a given value
     *
     * @param string $phrase Expected HTTP response reason phrase
     * @throws AssertionFailedException
     */
    #[Then('the response reason phrase is :phrase')]
    public function assertResponseReasonPhraseIs(string $phrase): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::same($phrase, $actual = $this->response->getReasonPhrase(), sprintf(
                'Expected response reason phrase "%s", got "%s".',
                $phrase,
                $actual,
            ));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the HTTP response reason phrase does not equal a given value
     *
     * @param string $phrase Reason phrase that the HTTP response should not equal
     * @throws AssertionFailedException
     */
    #[Then('the response reason phrase is not :phrase')]
    public function assertResponseReasonPhraseIsNot(string $phrase): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::notSame($phrase, $this->response->getReasonPhrase(), sprintf(
                'Did not expect response reason phrase "%s".',
                $phrase,
            ));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the HTTP response reason phrase matches a regular expression
     *
     * @param string $pattern Regular expression pattern
     * @throws AssertionFailedException
     */
    #[Then('the response reason phrase matches :expression')]
    public function assertResponseReasonPhraseMatches(string $pattern): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::regex(
                $actual = $this->response->getReasonPhrase(),
                $pattern,
                sprintf(
                    'Expected the response reason phrase to match the regular expression "%s", got "%s".',
                    $pattern,
                    $actual,
                ),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the HTTP response status line equals a given value
     *
     * @param string $line Expected HTTP response status line
     * @throws AssertionFailedException
     */
    #[Then('the response status line is :line')]
    public function assertResponseStatusLineIs(string $line): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            $actualStatusLine = sprintf(
                '%d %s',
                $this->response->getStatusCode(),
                $this->response->getReasonPhrase(),
            );

            Assertion::same($line, $actualStatusLine, sprintf(
                'Expected response status line "%s", got "%s".',
                $line,
                $actualStatusLine,
            ));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the HTTP response status line does not equal a given value
     *
     * @param string $line Value that the HTTP response status line must not equal
     * @throws AssertionFailedException
     */
    #[Then('the response status line is not :line')]
    public function assertResponseStatusLineIsNot(string $line): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            $actualStatusLine = sprintf(
                '%d %s',
                $this->response->getStatusCode(),
                $this->response->getReasonPhrase(),
            );

            Assertion::notSame($line, $actualStatusLine, sprintf(
                'Did not expect response status line "%s".',
                $line,
            ));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the HTTP response status line matches a regular expression
     *
     * @param string $pattern Regular expression pattern
     * @throws AssertionFailedException
     */
    #[Then('the response status line matches :expression')]
    public function assertResponseStatusLineMatches(string $pattern): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            $actualStatusLine = sprintf(
                '%d %s',
                $this->response->getStatusCode(),
                $this->response->getReasonPhrase(),
            );

            Assertion::regex(
                $actualStatusLine,
                $pattern,
                sprintf(
                    'Expected the response status line to match the regular expression "%s", got "%s".',
                    $pattern,
                    $actualStatusLine,
                ),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Checks if the HTTP response code is in a group
     *
     * Allowed groups are:
     *
     * - informational
     * - success
     * - redirection
     * - client error
     * - server error
     *
     * @param string $group Name of the group that the response code should be in
     * @throws AssertionFailedException
     */
    #[Then('the response is :group')]
    public function assertResponseIs(string $group): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        $range = $this->getResponseCodeGroupRange($group);
        $code  = $this->response->getStatusCode();

        try {
            Assertion::range($code, $range['min'], $range['max']);
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException(sprintf(
                'Expected response group "%s", got "%s" (response code: %d).',
                $group,
                $this->getResponseGroup($code),
                $code,
            ));
        }

        return true;
    }

    /**
     * Checks if the HTTP response code is *not* in a group
     *
     * Allowed groups are:
     *
     * - informational
     * - success
     * - redirection
     * - client error
     * - server error
     *
     * @param string $group Name of the group that the response code is not in
     * @throws AssertionFailedException
     */
    #[Then('the response is not :group')]
    public function assertResponseIsNot(string $group): bool
    {
        try {
            $this->assertResponseIs($group);
        } catch (AssertionFailedException $e) {
            // As expected, return
            return true;
        }

        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        throw new AssertionFailedException(sprintf(
            'Did not expect response to be in the "%s" group (response code: %d).',
            $group,
            $this->response->getStatusCode(),
        ));
    }

    /**
     * Assert that a response header exists
     *
     * @param string $header Then name of the header
     * @throws AssertionFailedException
     */
    #[Then('the :header response header exists')]
    public function assertResponseHeaderExists(string $header): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::true(
                $this->response->hasHeader($header),
                sprintf('The "%s" response header does not exist.', $header),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that a response header does not exist
     *
     * @param string $header Then name of the header
     * @throws AssertionFailedException
     */
    #[Then('the :header response header does not exist')]
    public function assertResponseHeaderDoesNotExist(string $header): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::false(
                $this->response->hasHeader($header),
                sprintf('The "%s" response header should not exist.', $header),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Compare a response header value against a string
     *
     * @param string $header The name of the header
     * @param string $value The value to compare with
     * @throws AssertionFailedException
     */
    #[Then('the :header response header is :value')]
    public function assertResponseHeaderIs(string $header, string $value): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::same(
                $actual = $this->response->getHeaderLine($header),
                $value,
                sprintf(
                    'Expected the "%s" response header to be "%s", got "%s".',
                    $header,
                    $value,
                    $actual,
                ),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that a response header is not a value
     *
     * @param string $header The name of the header
     * @param string $value The value to compare with
     * @throws AssertionFailedException
     */
    #[Then('the :header response header is not :value')]
    public function assertResponseHeaderIsNot(string $header, string $value): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::notSame(
                $this->response->getHeaderLine($header),
                $value,
                sprintf(
                    'Did not expect the "%s" response header to be "%s".',
                    $header,
                    $value,
                ),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Match a response header value against a regular expression pattern
     *
     * @param string $header The name of the header
     * @param string $pattern The regular expression pattern
     * @throws AssertionFailedException
     */
    #[Then('the :header response header matches :pattern')]
    public function assertResponseHeaderMatches(string $header, string $pattern): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        try {
            Assertion::regex(
                $actual = $this->response->getHeaderLine($header),
                $pattern,
                sprintf(
                    'Expected the "%s" response header to match the regular expression "%s", got "%s".',
                    $header,
                    $pattern,
                    $actual,
                ),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the response body is empty
     *
     * @throws AssertionFailedException
     */
    #[Then('the response body is empty')]
    public function assertResponseBodyIsEmpty(): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        $body = (string) $this->response->getBody();

        try {
            Assertion::noContent($body, sprintf('Expected response body to be empty, got "%s".', $body));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the response body matches some content
     *
     * @param PyStringNode $content The content to match the response body against
     * @throws AssertionFailedException
     */
    #[Then('the response body is:')]
    public function assertResponseBodyIs(PyStringNode $content): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        $contentString = (string) $content;

        try {
            Assertion::same($body = (string) $this->response->getBody(), $contentString, sprintf(
                'Expected response body "%s", got "%s".',
                $contentString,
                $body,
            ));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the response body does not match some content
     *
     * @param PyStringNode $content The content that the response body should not match
     * @throws AssertionFailedException
     */
    #[Then('the response body is not:')]
    public function assertResponseBodyIsNot(PyStringNode $content): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        $contentString = (string) $content;

        try {
            Assertion::notSame((string) $this->response->getBody(), $contentString, sprintf(
                'Did not expect response body to be "%s".',
                $contentString,
            ));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the response body matches some content using a regular expression
     *
     * @param PyStringNode $pattern The regular expression pattern to use for the match
     * @throws AssertionFailedException
     */
    #[Then('the response body matches:')]
    public function assertResponseBodyMatches(PyStringNode $pattern): bool
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        $patternString = (string) $pattern;

        try {
            Assertion::regex($body = (string) $this->response->getBody(), $patternString, sprintf(
                'Expected response body to match regular expression "%s", got "%s".',
                $patternString,
                $body,
            ));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Get the min and max values for a response body group
     *
     * @param string $group The name of the group
     * @throws InvalidArgumentException
     * @return array{min:int,max:int} An array with two keys, min and max, which represents the
     *                                min and max values for $group
     */
    protected function getResponseCodeGroupRange(string $group): array
    {
        switch ($group) {
            case 'informational':
                $min = 100;
                $max = 199;
                break;
            case 'success':
                $min = 200;
                $max = 299;
                break;
            case 'redirection':
                $min = 300;
                $max = 399;
                break;
            case 'client error':
                $min = 400;
                $max = 499;
                break;
            case 'server error':
                $min = 500;
                $max = 599;
                break;
            default:
                throw new InvalidArgumentException(sprintf('Invalid response code group: %s', $group));
        }

        return [
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Get the "response group" based on a status code
     *
     * @param int $code The respose code
     */
    protected function getResponseGroup(int $code): string
    {
        return match (true) {
            $code >= 500 => 'server error',
            $code >= 400 => 'client error',
            $code >= 300 => 'redirection',
            $code >= 200 => 'success',
            default => 'informational',
        };
    }

    /**
     * Validate a response code
     *
     * @param int $code
     * @throws InvalidArgumentException
     * @return int
     */
    protected function validateResponseCode(int $code): int
    {
        try {
            Assertion::range($code, 100, 599, sprintf('Response code must be between 100 and 599, got %d.', $code));
        } catch (AssertionFailure $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        return $code;
    }
}
