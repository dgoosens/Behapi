<?php declare(strict_types=1);
namespace Behapi\PhpMatcher;

use InvalidArgumentException;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

use Behapi\Http\Response;
use Behapi\HttpHistory\History as HttpHistory;

use function sprintf;
use function json_encode;
use function json_decode;

class JsonContext implements Context
{
    use Response;

    /** @var MatcherFactory */
    private $factory;

    /** @var PropertyAccessor */
    private $accessor;

    public function __construct(HttpHistory $history)
    {
        $this->history = $history;
        $this->factory = new MatcherFactory;
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    /** @Then the root should match: */
    final public function root_should_match(PyStringNode $pattern): void
    {
        $matcher = $this->factory->createMatcher();

        if ($matcher->match($this->getJson(), $pattern->getRaw())) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'The json root does not match with the given pattern (error : %s)',
                $matcher->getError()
            )
        );
    }

    /** @Then in the json, :path should match: */
    final public function path_should_match(string $path, PyStringNode $pattern): void
    {
        $value = $this->getValue($path);
        $matcher = $this->factory->createMatcher();

        $json = json_encode($value);

        if ($matcher->match($json, $pattern->getRaw())) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'The json path "%s" does not match with the given pattern (error : %s)',
                $path,
                $matcher->getError()
            )
        );
    }

    /** @Then in the json, :path should not match: */
    final public function path_should_not_match(string $path, PyStringNode $pattern): void
    {
        $value = $this->getValue($path);
        $matcher = $this->factory->createMatcher();

        $json = json_encode($value);

        if (!$matcher->match($json, $pattern->getRaw())) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'The json path "%s" matches with the given pattern (error : %s)',
                $path,
                $matcher->getError()
            )
        );
    }

    private function getJson(): ?stdClass
    {
        return json_decode((string) $this->getResponse()->getBody());
    }

    private function getValue(string $path)
    {
        $json = $this->getJson();

        if (null === $json) {
            throw new InvalidArgumentException('Expected a Json valid content, got none');
        }

        return $this->accessor->getValue($json, $path);
    }
}
