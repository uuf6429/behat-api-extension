<?php declare(strict_types=1);

namespace Imbo\BehatApiExtension\Context;

use Assert\Assertion;
use Assert\AssertionFailedException as AssertionFailure;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Imbo\BehatApiExtension\ArrayContainsComparator\Matcher\JWT as JwtMatcher;
use Imbo\BehatApiExtension\Exception\AssertionFailedException;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * Behat feature context that can be used to simplify testing of JSON-based RESTful HTTP APIs
 */
class JsonApiContext implements ApiClientAwareContext, ArrayContainsComparatorAwareContext
{
    use BaseApiContextTrait;

    /**
     * Add a JWT token to the matcher
     *
     * @param string $name String identifying the token
     * @param string $secret The secret used to sign the token
     * @param PyStringNode $payload The payload for the JWT
     * @throws RuntimeException
     */
    #[Given('the response body contains a JWT identified by :name, signed with :secret:')]
    public function addJwtToken(string $name, string $secret, PyStringNode $payload): static
    {
        $jwtMatcher = $this->arrayContainsComparator->getMatcherFunction('jwt');

        if (!($jwtMatcher instanceof JwtMatcher)) {
            throw new RuntimeException(sprintf(
                'Matcher registered for the @jwt() matcher function must be an instance of %s',
                JwtMatcher::class,
            ));
        }

        $jwtMatcher->addToken($name, $this->jsonDecode((string)$payload), $secret);

        return $this;
    }

    /**
     * Assert that the response body contains an empty JSON object
     *
     * @throws AssertionFailedException
     */
    #[Then('the response body is an empty JSON object')]
    public function assertResponseBodyIsAnEmptyJsonObject(): bool
    {
        $this->requireResponse();
        $body = $this->getResponseBody();

        try {
            Assertion::isInstanceOf($body, stdClass::class, 'Expected response body to be a JSON object.');
            Assertion::same('{}', $encoded = json_encode($body, JSON_PRETTY_PRINT), sprintf(
                'Expected response body to be an empty JSON object, got "%s".',
                $encoded,
            ));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the response body contains an empty JSON array
     *
     * @throws AssertionFailedException
     */
    #[Then('the response body is an empty JSON array')]
    public function assertResponseBodyIsAnEmptyJsonArray(): bool
    {
        $this->requireResponse();

        try {
            Assertion::same(
                [],
                $body = $this->getResponseBodyArray(),
                sprintf('Expected response body to be an empty JSON array, got "%s".', json_encode($body, JSON_PRETTY_PRINT)),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the response body contains an array with a specific length
     *
     * @param int|string $length The length of the array
     * @throws AssertionFailedException
     */
    #[Then('the response body is a JSON array of length :length')]
    public function assertResponseBodyJsonArrayLength(int|string $length): bool
    {
        $this->requireResponse();
        $length = (int)$length;

        try {
            Assertion::count(
                $body = $this->getResponseBodyArray(),
                $length,
                sprintf(
                    'Expected response body to be a JSON array with %d entr%s, got %d: "%s".',
                    $length,
                    $length === 1 ? 'y' : 'ies',
                    count($body),
                    json_encode($body, JSON_PRETTY_PRINT),
                ),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the response body contains an array with a length of at least a given length
     *
     * @param int|string $length The length to use in the assertion
     * @throws AssertionFailedException
     */
    #[Then('the response body is a JSON array with a length of at least :length')]
    public function assertResponseBodyJsonArrayMinLength(int|string $length): bool
    {
        $this->requireResponse();

        $length = (int)$length;
        $body = $this->getResponseBodyArray();

        try {
            Assertion::min(
                $bodyLength = count($body),
                $length,
                sprintf(
                    'Expected response body to be a JSON array with at least %d entr%s, got %d: "%s".',
                    $length,
                    $length === 1 ? 'y' : 'ies',
                    $bodyLength,
                    json_encode($body, JSON_PRETTY_PRINT),
                ),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the response body contains an array with a length of at most a given length
     *
     * @param int|string $length The length to use in the assertion
     * @throws AssertionFailedException
     */
    #[Then('the response body is a JSON array with a length of at most :length')]
    public function assertResponseBodyJsonArrayMaxLength(int|string $length): bool
    {
        $this->requireResponse();

        $length = (int)$length;
        $body = $this->getResponseBodyArray();

        try {
            Assertion::max(
                $bodyLength = count($body),
                $length,
                sprintf(
                    'Expected response body to be a JSON array with at most %d entr%s, got %d: "%s".',
                    $length,
                    $length === 1 ? 'y' : 'ies',
                    $bodyLength,
                    json_encode($body, JSON_PRETTY_PRINT),
                ),
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Assert that the response body contains all keys / values in the parameter
     *
     * @param PyStringNode $contains
     * @throws AssertionFailedException
     */
    #[Then('the response body contains JSON:')]
    public function assertResponseBodyContainsJson(PyStringNode $contains): bool
    {
        $this->requireResponse();

        // Decode the parameter to the step as an array and make sure it's valid JSON
        $containsJson = $this->jsonDecode((string)$contains);

        // Get the decoded response body and make sure it's decoded to an array
        /** @var array<array-key, mixed> $body */
        $body = json_decode((string)json_encode($this->getResponseBody()), true);

        try {
            // Compare the arrays, on error this will throw an exception
            Assertion::true($this->arrayContainsComparator->compare($containsJson, $body));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException(
                'Comparator did not return in a correct manner. Marking assertion as failed.',
            );
        }

        return true;
    }

    /**
     * Get the JSON-encoded array or stdClass from the response body
     *
     * @return array<array-key, mixed>|stdClass
     * @throws InvalidArgumentException
     */
    protected function getResponseBody(): array|stdClass
    {
        if (!$this->response) {
            throw new RuntimeException(static::MISSING_RESPONSE_ERROR);
        }

        $body = json_decode((string)$this->response->getBody());

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('The response body does not contain valid JSON data.');
        }
        if (!is_array($body) && !($body instanceof stdClass)) {
            throw new InvalidArgumentException('The response body does not contain a valid JSON array / object.');
        }

        /** @var array<array-key, mixed>|stdClass */
        return $body;
    }

    /**
     * Get the response body as an array
     *
     * @return array<array-key, mixed>
     * @throws InvalidArgumentException
     */
    protected function getResponseBodyArray(): array
    {
        $body = $this->getResponseBody();

        if (!is_array($body)) {
            throw new InvalidArgumentException('The response body does not contain a valid JSON array.');
        }

        return $body;
    }

    /**
     * Convert some variable to a JSON-array
     *
     * @param string $value The value to decode
     * @param string|null $errorMessage Optional error message
     * @return array<array-key, mixed>
     * @throws InvalidArgumentException
     */
    protected function jsonDecode(string $value, ?string $errorMessage = null): array
    {
        /** @var array<array-key, mixed> $decoded */
        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                $errorMessage ?: 'The supplied parameter is not a valid JSON object.',
            );
        }

        return $decoded;
    }
}
