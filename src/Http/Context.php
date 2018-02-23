<?php declare(strict_types=1);
namespace Behapi\Http;

use RuntimeException;

use Psr\Http\Message\RequestInterface;

use Behat\Gherkin\Node\TableNode;
use Behat\Behat\Context\Context as BehatContext;

use Http\Client\HttpClient;
use Http\Message\StreamFactory;
use Http\Message\MessageFactory;
use Http\Discovery\HttpClientDiscovery;

use Webmozart\Assert\Assert;

use Behapi\EventListener\Events;
use Behapi\EventListener\RequestEvent;

use Behapi\HttpHistory\History as HttpHistory;

use function trim;
use function is_array;
use function http_build_query;

class Context implements BehatContext
{
    use Builder;
    use Response;

    /** @var RequestInterface */
    private $request;

    /** @var mixed[] Query args to add */
    private $query;

    /** @var HttpClient|HttpAsyncClient */
    private $client;

    public function __construct(PluginClientBuilder $builder, StreamFactory $streamFactory, MessageFactory $messageFactory, HttpHistory $history)
    {
        $this->builder = $builder;
        $this->history = $history;
        $this->streamFactory = $streamFactory;
        $this->messageFactory = $messageFactory;

        $this->client = HttpClientDiscovery::find();
    }

    /** @When /^I create a "(?P<method>GET|POST|PATCH|PUT|DELETE|OPTIONS|HEAD)" request to "(?P<url>.+?)"$/ */
    public function create_a_request(string $method, string $url): void
    {
        $url = trim($url);

        $this->query = [];
        $this->request = $this->messageFactory->createRequest(strtoupper($method), $url);

        // let's set a default content-type
        $this->set_content_type($this->getDefaultContentType());
    }

    /**
     * @When /^I send a "(?P<method>GET|POST|PATCH|PUT|DELETE|OPTIONS|HEAD)" request to "(?P<url>.+?)"$/
     *
     * -------
     *
     * Shortcut for `When I create a X request to Then send the request`
     */
    public function send_a_request($method, $url): void
    {
        $this->create_a_request($method, $url);
        $this->send_request();
    }

    /** @When I add/set the value :value to the parameter :parameter */
    public function add_a_parameter(string $parameter, string $value): void
    {
        if (!isset($this->query[$parameter])) {
            $this->query[$parameter] = $value;
            return;
        }

        $current = &$this->query[$parameter];

        if (is_array($current)) {
            $current[] = $value;
            return;
        }

        $current = [$current, $value];
    }

    /** @When I set the following query arguments: */
    public function set_the_parameters(TableNode $parameters): void
    {
        $this->query = [];

        foreach ($parameters->getRowsHash() as $parameter => $value) {
            $this->add_a_parameter($parameter, $value);
        }
    }

    /** @When I set the content-type to :type */
    public function set_content_type(string $type): void
    {
        $request = $this->getRequest();
        $this->request = $request->withHeader('Content-Type', $type);
    }

    /** @When I set the following body: */
    public function set_the_body(string $body): void
    {
        $stream = $this->streamFactory->createStream($body);

        $request = $this->getRequest();
        $this->request = $request->withBody($stream);
    }

    /** @When I add/set the value :value to the header :header */
    public function add_header(string $header, string $value): void
    {
        $request = $this->getRequest();
        $this->request = $request->withAddedHeader($header, $value);
    }

    /** @When I set the headers: */
    public function set_headers(TableNode $headers): void
    {
        $request = $this->getRequest();

        foreach ($headers->getRowsHash() as $header => $value) {
            $request = $request->withHeader($header, $value);
        }

        $this->request = $request;
    }

    /** @When I send the request */
    public function send_request(): void
    {
        $request = $this->getRequest();

        if (!empty($this->query)) {
            $uri = $request->getUri();
            $current = $uri->getQuery();
            $query = http_build_query($this->query);

            if (!empty($current)) {
                $query = "{$current}&{$query}";
            }

            $uri = $uri->withQuery($query);
            $request = $request->withUri($uri);
        }

        $client = $this->builder->createClient($this->client);
        $client->sendRequest($request);
    }

    /** @Then the status code should be :expected */
    public function status_code_should_be(int $expected): void
    {
        $response = $this->getResponse();
        Assert::same((int) $response->getStatusCode(), $expected);
    }

    /** @Then the status code should not be :expected */
    public function status_code_should_not_be(int $expected): void
    {
        $response = $this->getResponse();
        Assert::notSame((int) $response->getStatusCode(), $expected);
    }

    /** @Then the content-type should be equal to :expected */
    public function content_type_should_be(string $expected): void
    {
        $response = $this->getResponse();
        Assert::same($response->getHeaderLine('Content-type'), $expected);
    }

    /** @Then the response header :header should be equal to :expected */
    public function header_should_be(string $header, string $expected): void
    {
        $response = $this->getResponse();
        Assert::same($response->getHeaderLine($header), $expected);
    }

    /** @Then the response header :header should contain :expected */
    public function header_should_contain(string $header, string $expected): void
    {
        $response = $this->getResponse();
        Assert::contains((string) $response->getHeaderLine($header), $expected);
    }

    /** @Then the response should have a header :header */
    public function response_should_have_header(string $header): void
    {
        $response = $this->getResponse();
        Assert::true($response->hasHeader($header));
    }

    /** @Then the response should have sent some data */
    public function response_should_have_sent_some_data(): void
    {
        $body = $this->getResponse()->getBody();

        Assert::notNull($body->getSize());
        Assert::greaterThan($body->getSize(), 0);
    }

    /** @Then the response should not have sent any data */
    public function response_should_not_have_any_data(): void
    {
        $body = $this->getResponse()->getBody();
        Assert::nullOrSame($body->getSize(), 0);
    }

    /** @Then the response should contain :data */
    public function response_should_contain(string $data): void
    {
        $response = $this->getResponse();
        Assert::contains((string) $response->getBody(), $data);
    }

    /** @Then the response should not contain :data */
    public function response_should_not_contain(string $data): void
    {
        $response = $this->getResponse();
        Assert::notContains((string) $response->getBody(), $data);
    }

    /** @Then the response should be :data */
    public function response_should_be(string $data): void
    {
        $response = $this->getResponse();
        Assert::eq((string) $response->getBody(), $data);
    }

    /** @Then the response should not be :data */
    public function response_should_not_be(string $data): void
    {
        $response = $this->getResponse();
        Assert::NotEq((string) $response->getBody(), $data);
    }

    /** @AfterScenario @api */
    public function clearCache(): void
    {
        $this->query = [];
        $this->request = null;
        // the history is resetted in its own event listener
    }

    public function getRequest(): RequestInterface
    {
        if (null === $this->request) {
            throw new RuntimeException('No request initiated');
        }

        return $this->request;
    }

    protected function getDefaultContentType(): string
    {
        return 'application/json';
    }
}
