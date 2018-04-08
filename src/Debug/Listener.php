<?php declare(strict_types=1);
namespace Behapi\Debug;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Behat\Testwork\Tester\Result\TestResult;
use Behat\Testwork\Tester\Result\TestResults;
use Behat\Testwork\EventDispatcher\Event\AfterTested;

use Behat\Behat\EventDispatcher\Event\OutlineTested;
use Behat\Behat\EventDispatcher\Event\ScenarioTested;
use Behat\Behat\EventDispatcher\Event\BackgroundTested;
use Behat\Behat\EventDispatcher\Event\GherkinNodeTested;

use Behat\Gherkin\Node\TaggedNodeInterface;

use Behapi\Debug\Introspection\Adapter;
use Behapi\HttpHistory\History as HttpHistory;

use function printf;
use function current;
use function method_exists;
use function iterator_to_array;

/**
 * Debug http
 *
 * Allows to debug a scenario, or, if the debug is activated, to print
 * a message if a scenario failed for http requests.
 *
 * @author Baptiste Clavié <clavie.b@gmail.com>
 */
final class Listener implements EventSubscriberInterface
{
    /** @var HttpHistory */
    private $history;

    /** @var Configuration */
    private $configuration;

    /* @var Adapter[] */
    private $adapters;

    /** @param Adapter[] $adapters Introspection adapters to use in this listener (sorted by priority) */
    public function __construct(Configuration $configuration, HttpHistory $history, array $adapters)
    {
        $this->history = $history;
        $this->adapters = $adapters;
        $this->configuration = $configuration;
    }

    /** {@inheritDoc} */
    public static function getSubscribedEvents()
    {
        return [
            OutlineTested::AFTER => 'debugAfter',
            ScenarioTested::AFTER => 'debugAfter',
            BackgroundTested::AFTER => 'debugAfter',
        ];
    }

    public function debugAfter(GherkinNodeTested $event): void
    {
        if (!$event instanceof AfterTested) {
            return;
        }

        // no http tag... still no chocolates
        if (!$this->hasTag($event, 'http')) {
            return;
        }

        // debug only if tag is present (all debug) or only on test failures
        if ($this->hasTag($event, 'debug')) {
            $tuples = current($this->history);

            foreach ($tuples as $tuple) {
                foreach ($tuple as list($request, $response)) {
                    $this->debug($request, $response);
                }
            }

            return;
        }

        if (false === $this->configuration->getStatus()) {
            return;
        }

        $result = $event->getTestResult();

        if (TestResult::FAILED !== $result->getResultCode()) {
            return;
        }

        // all ->getTestResult() returns actually TestResults even for simple
        // scenarios. So we have to ensure that if we are not testing against
        // OutlineTested, we need to wrap the result in an array
        if ($result instanceof TestResults && !$event instanceof OutlineTested) {
            $result = [$result];
        }

        $values = iterator_to_array($this->history);
        $key = -1;

        foreach ($result as $testResult) {
            ++$key;

            if (TestResult::FAILED !== $testResult->getResultCode()) {
                continue;
            }

            // no history created
            if (!isset($values[$key])) {
                continue;
            }

            foreach ($values[$key] as list($request, $response)) {
                $this->debug($request, $response);
            }
        }
    }

    private function debug(?RequestInterface $request, ?ResponseInterface $response): void
    {
        $messages = [
            RequestInterface::class => $request,
            ResponseInterface::class => $response,
        ];

        foreach ($messages as $interface => $message) {
            if (!$message instanceof $interface) {
                continue;
            }

            foreach ($this->adapters as $adapter) {
                if (!$adapter->supports($message)) {
                    continue;
                }

                $adapter->introspect($message, $this->configuration->getRequestHeaders());
                break;
            }
        }
    }

    private function hasTag(GherkinNodeTested $event, string $tag): bool
    {
        $node = $event->getNode();

        // no tags, no chocolates
        if (!$node instanceof TaggedNodeInterface) {
            return false;
        }

        if ($node->hasTag($tag)) {
            return true;
        }

        if (!method_exists($event, 'getFeature')) {
            return false;
        }

        $feature = $event->getFeature();

        return $feature->hasTag($tag);
    }
}
